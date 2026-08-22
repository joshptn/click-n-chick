<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Address;
use App\Models\Food;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\DeliveryQuote;
use App\Services\Store\StoreAvailability;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Dispatch step: pickup, delivery, service area, fees, and place-order.
 *
 * The governing property throughout is that the client supplies a selection
 * and a destination, never a price - so these lean on asserting what the
 * server computes rather than what the request asked for.
 */
class CheckoutDispatchTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    /** Roughly 1.5 km from the store: inside the base-rate band. */
    private const NEAR = ['latitude' => 14.9700, 120 => null, 'longitude' => 120.7600];

    /** Metro Manila, ~55 km out: beyond the 45 km radius. */
    private const FAR = ['latitude' => 14.5995, 'longitude' => 120.9842];

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-morning, mid-week: comfortably inside trading hours, so a test
        // about delivery fees is not also a test about opening times.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', 'Asia/Manila')->utc());

        // Only the two broadcasts. A blanket Event::fake() would also silence
        // Eloquent's model events, which Setting's cache invalidation rides on
        // - several tests here change a setting and expect it to take effect.
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(array $overrides = []): User
    {
        $phone = '+63917000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'first_name' => 'Julia',
            'last_name' => 'Veneese',
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ], $overrides))->fresh();
    }

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Herb Roasted Chicken',
            'description' => 'Whole.',
            'price' => 350,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 20,
        ], $overrides));
    }

    /** @return array{0: User, 1: int} the customer and their cart line id */
    private function customerWithCart(?Food $food = null, int $quantity = 1): array
    {
        $user = $this->customer();
        $food ??= $this->food();

        $lineId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => $quantity])
            ->assertCreated()
            ->json('cart_item_id');

        return [$user, $lineId];
    }

    /** A body that clears every gate except the one under test. */
    private function pickupBody(array $overrides = []): array
    {
        return array_merge([
            'fulfilment_type' => 'pickup',
            'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
        ], $overrides);
    }

    private function deliveryBody(array $overrides = []): array
    {
        return array_merge([
            'fulfilment_type' => 'delivery',
            'latitude' => 14.9700,
            'longitude' => 120.7600,
            'full_address' => 'ADD Street, Apalit',
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // UC-DEL-004 / 005: service area and fee
    // -----------------------------------------------------------------

    public function test_the_service_radius_is_forty_five_kilometres(): void
    {
        $this->assertSame(45.0, app(DeliveryQuote::class)->radiusKm());
    }

    public function test_a_nearby_address_is_inside_the_service_area(): void
    {
        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertTrue($quote['within_service_area']);
        $this->assertLessThan(5, $quote['distance_km']);
    }

    public function test_an_address_beyond_the_radius_is_refused_with_a_notice(): void
    {
        $quote = app(DeliveryQuote::class)->for(self::FAR['latitude'], self::FAR['longitude']);

        $this->assertFalse($quote['within_service_area']);
        $this->assertGreaterThan(45, $quote['distance_km']);
        $this->assertNull($quote['fee'], 'There is no price for a delivery that will not happen.');
        $this->assertStringContainsString('outside our', $quote['message']);
    }

    public function test_the_base_fee_covers_the_first_three_kilometres(): void
    {
        $pricing = app(DeliveryQuote::class);

        $this->assertSame(55.0, $pricing->for(14.9700, 120.7600)['fee']);
    }

    public function test_the_fee_rises_by_the_increment_past_the_base_distance(): void
    {
        // ~9 km north of the store: 6 chargeable km past the 3 km base.
        $quote = app(DeliveryQuote::class)->for(15.0396, 120.7584);

        $this->assertGreaterThan(55.0, $quote['fee']);
        $this->assertSame(
            round(55.0 + (ceil($quote['distance_km'] - 3) * 10.0), 2),
            $quote['fee']
        );
    }

    public function test_the_fee_follows_the_store_managers_configuration(): void
    {
        Setting::put(Setting::DELIVERY_BASE_FEE, 80);
        Setting::put(Setting::DELIVERY_EXTRA_FEE_PER_KM, 25);

        $this->assertSame(80.0, app(DeliveryQuote::class)->for(14.9700, 120.7600)['fee']);
    }

    public function test_the_delivery_quote_endpoint_prices_a_pin(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/delivery/quote', ['latitude' => 14.9700, 'longitude' => 120.7600])
            ->assertOk()
            ->assertJsonPath('within_service_area', true)
            ->assertJsonPath('fee', 55)
            ->assertJsonStructure(['distance_km', 'radius_km', 'origin', 'pricing']);
    }

    public function test_the_delivery_quote_endpoint_requires_a_session(): void
    {
        $this->postJson('/api/delivery/quote', ['latitude' => 14.97, 'longitude' => 120.76])
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // The checkout quote: totals
    // -----------------------------------------------------------------

    public function test_a_pickup_quote_carries_no_delivery_fee(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('subtotal', 350)
            ->assertJsonPath('delivery_fee', 0)
            ->assertJsonPath('total', 350);
    }

    public function test_a_delivery_quote_adds_the_fee_to_the_total(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody())
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('subtotal', 350)
            ->assertJsonPath('delivery_fee', 55)
            ->assertJsonPath('total', 405);
    }

    /** BR-25: the customer checks out a subset, and is priced on that subset. */
    public function test_only_the_selected_cart_lines_are_quoted(): void
    {
        [$user, $firstLine] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food(['food_name' => 'Buttered Corn', 'price' => 60])->id])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['cart_item_ids' => [$firstLine]]))
            ->assertOk()
            ->assertJsonPath('subtotal', 350)
            ->assertJsonCount(1, 'items');
    }

    public function test_a_cart_line_belonging_to_someone_else_is_not_priced(): void
    {
        [$user] = $this->customerWithCart();
        [, $strangersLine] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['cart_item_ids' => [$strangersLine]]))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'EMPTY_SELECTION');
    }

    // -----------------------------------------------------------------
    // The checkout quote: gates
    // -----------------------------------------------------------------

    public function test_a_delivery_without_a_location_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody(['latitude' => null, 'longitude' => null]))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'LOCATION_REQUIRED');
    }

    public function test_a_delivery_outside_the_radius_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody(self::FAR))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'OUTSIDE_SERVICE_AREA')
            ->assertJsonPath('delivery_fee', 0);
    }

    public function test_a_pickup_without_a_time_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['pickup_at' => null]))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'PICKUP_TIME_REQUIRED');
    }

    public function test_a_pickup_time_inside_the_kitchen_lead_time_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody([
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->addMinutes(5)->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'PICKUP_TIME_TOO_SOON');
    }

    public function test_a_pickup_time_after_the_last_slot_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody([
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(19, 55)->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'PICKUP_TIME_TOO_LATE');
    }

    public function test_a_closed_store_blocks_checkout(): void
    {
        [$user] = $this->customerWithCart();

        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_CLOSED);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'STORE_CLOSED_MANUALLY');
    }

    public function test_paused_delivery_blocks_a_delivery_but_not_a_pickup(): void
    {
        [$user] = $this->customerWithCart();

        Setting::put(Setting::STORE_DELIVERY_ENABLED, false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody())
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'DELIVERY_UNAVAILABLE');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', true);
    }

    public function test_a_sold_out_item_blocks_checkout(): void
    {
        $food = $this->food();
        [$user] = $this->customerWithCart($food);

        $food->forceFill(['stock_quantity' => 0])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'ITEM_UNAVAILABLE');
    }

    public function test_a_malformed_mobile_number_blocks_checkout(): void
    {
        [$user] = $this->customerWithCart($this->food(), 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['contact_phone' => '12345']))
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'CONTACT_PHONE_INVALID');
    }

    public function test_a_plus_63_mobile_number_is_accepted_and_normalised(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['contact_phone' => '+63 917 123 4567']))
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('contact.phone', '09171234567');
    }

    public function test_the_contact_falls_back_to_the_account(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody([
                'contact_name' => null,
                'contact_phone' => null,
            ]))
            ->assertOk()
            ->assertJsonPath('contact.name', 'Julia Veneese')
            ->assertJsonPath('can_place', true);
    }

    // -----------------------------------------------------------------
    // Saved addresses (UC-PROF-009 feeding UC-ORD-004)
    // -----------------------------------------------------------------

    public function test_a_saved_address_supplies_the_destination(): void
    {
        [$user] = $this->customerWithCart();

        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'home',
            'full_address' => 'Larlin Village, Apalit',
            'latitude' => 14.9700,
            'longitude' => 120.7600,
            'location' => 'Apalit',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'fulfilment_type' => 'delivery',
                'address_id' => $address->id,
                'contact_name' => 'Julia Veneese',
                'contact_phone' => '09171234567',
            ])
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('destination.source', 'saved_address')
            ->assertJsonPath('destination.full_address', 'Larlin Village, Apalit')
            ->assertJsonPath('delivery_fee', 55);
    }

    public function test_another_accounts_address_cannot_be_used_as_a_destination(): void
    {
        [$user] = $this->customerWithCart();
        $stranger = $this->customer();

        $theirs = Address::create([
            'user_id' => $stranger->id,
            'label' => 'home',
            'full_address' => 'Somewhere else',
            'latitude' => 14.9700,
            'longitude' => 120.7600,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'fulfilment_type' => 'delivery',
                'address_id' => $theirs->id,
                'contact_name' => 'Julia Veneese',
                'contact_phone' => '09171234567',
            ])
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'LOCATION_REQUIRED');
    }

    // -----------------------------------------------------------------
    // Discount reporting (claimed in the next step, never here)
    // -----------------------------------------------------------------

    public function test_the_quote_reports_the_discount_without_applying_it(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('discount.applied', false)
            ->assertJsonPath('discount.amount', 0)
            ->assertJsonPath('total', 350);
    }

    // -----------------------------------------------------------------
    // Place order: the quote is re-run, and it is the authority
    // -----------------------------------------------------------------

    public function test_placing_a_pickup_order_records_the_dispatch_details(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickupBody())
            ->assertCreated()
            ->assertJsonPath('order.order_type', 'pickup');

        $order = Order::firstOrFail();

        $this->assertSame('350.00', $order->subtotal);
        $this->assertSame('0.00', $order->delivery_fee);
        $this->assertSame('350.00', $order->total_amount);
        $this->assertNotNull($order->pickup_at);
        $this->assertNull($order->latitude, 'A pickup order has no delivery destination.');
    }

    public function test_placing_a_delivery_order_snapshots_the_destination_and_distance(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody())
            ->assertCreated();

        $order = Order::firstOrFail();

        $this->assertSame('delivery', $order->order_type);
        $this->assertSame('55.00', $order->delivery_fee);
        $this->assertSame('405.00', $order->total_amount);
        $this->assertSame('ADD Street, Apalit', $order->full_address);
        $this->assertNotNull($order->delivery_distance_km);
        $this->assertNull($order->pickup_at);
    }

    /** The client sends a selection and a destination. Never a price. */
    public function test_a_price_in_the_request_body_is_ignored(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody([
                'total_price' => 1,
                'delivery_fee' => 0,
                'subtotal' => 1,
            ]))
            ->assertCreated();

        $this->assertSame('405.00', Order::firstOrFail()->total_amount);
    }

    public function test_placing_an_order_outside_the_service_area_is_refused(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody(self::FAR))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'OUTSIDE_SERVICE_AREA');

        $this->assertSame(0, Order::count());
    }

    public function test_placing_an_order_while_closed_is_refused(): void
    {
        [$user] = $this->customerWithCart();

        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_CLOSED);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickupBody())
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'STORE_CLOSED_MANUALLY');

        $this->assertSame(0, Order::count());
    }

    /** BR-25 again: what was not checked out stays in the cart. */
    public function test_placing_an_order_clears_only_the_checked_out_lines(): void
    {
        [$user, $firstLine] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food(['food_name' => 'Buttered Corn', 'price' => 60])->id])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickupBody(['cart_item_ids' => [$firstLine]]))
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(1, 'cart')
            ->assertJsonPath('cart.0.food_name', 'Buttered Corn');
    }

    public function test_placing_an_order_without_a_fulfilment_type_is_refused(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', ['contact_phone' => '09171234567'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'FULFILMENT_TYPE_REQUIRED');
    }
}
