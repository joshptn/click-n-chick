<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Http\Controllers\CartController;
use App\Models\Address;
use App\Models\Food;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guest checkout and placement (UC-GUEST-003/004).
 *
 * The quote is shared with the signed-in path, so most of its arithmetic is
 * already proven in CheckoutDispatchTest. What is specific to a guest is what
 * they must supply (an email), what they cannot have (a discount, an address
 * book), and the credential they are handed once the order exists.
 */
class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila')->utc());

        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);
        Cache::flush();

        config()->set('services.routing.openrouteservice.api_key', 'test-key');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
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

    private function fakeRouting(float $km): void
    {
        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [['summary' => ['distance' => $km, 'duration' => $km * 90]]],
            ]),
        ]);
    }

    /** A guest cart with one line, and the token that names it. */
    private function guestCart(): string
    {
        return $this->postJson('/api/guest/cart/items', ['food_id' => $this->food()->id])
            ->assertCreated()
            ->json('guest_token');
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'fulfilment_type' => 'pickup',
            'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
            'contact_email' => 'julia@example.test',
        ], $overrides);
    }

    private function asGuest(string $token): static
    {
        return $this->withHeader(CartController::GUEST_HEADER, $token);
    }

    // -----------------------------------------------------------------
    // Quoting
    // -----------------------------------------------------------------

    public function test_a_guest_can_price_their_cart(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/checkout/quote', $this->body())
            ->assertOk()
            ->assertJsonPath('subtotal', 350)
            ->assertJsonPath('total', 350)
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('contact.email', 'julia@example.test');
    }

    public function test_a_guest_with_no_cart_has_nothing_to_price(): void
    {
        $this->postJson('/api/guest/checkout/quote', $this->body())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'EMPTY_SELECTION');
    }

    public function test_a_guest_must_supply_an_email(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/checkout/quote', $this->body(['contact_email' => '']))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'CONTACT_EMAIL_REQUIRED');
    }

    public function test_a_malformed_email_is_reported_in_place_rather_than_as_a_validation_error(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/checkout/quote', $this->body(['contact_email' => 'julia@@nope']))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'CONTACT_EMAIL_INVALID');
    }

    /** The account path must not have grown an email requirement. */
    public function test_a_signed_in_customer_is_not_asked_for_an_email(): void
    {
        $user = User::factory()->create(['email' => 'account@example.test']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food()->id])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'fulfilment_type' => 'pickup',
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
                'contact_name' => 'Account Holder',
                'contact_phone' => '09171234567',
            ])
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('contact.email', 'account@example.test');
    }

    // -----------------------------------------------------------------
    // BR-05: what a guest may not have
    // -----------------------------------------------------------------

    public function test_a_guest_asking_for_a_discount_is_told_why_not(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'DISCOUNT_NOT_ELIGIBLE')
            ->assertJsonPath('discount.amount', 0)
            ->assertJsonPath('discount.eligible', false);
    }

    public function test_a_guest_cannot_borrow_a_saved_address(): void
    {
        $owner = User::factory()->create();

        $address = Address::create([
            'user_id' => $owner->id,
            'label' => 'Home',
            'full_address' => 'Somewhere, Apalit',
            'latitude' => 14.9700,
            'longitude' => 120.7600,
            'is_default' => true,
        ]);

        $token = $this->guestCart();
        $this->fakeRouting(6.0);

        $this->asGuest($token)
            ->postJson('/api/guest/checkout/quote', $this->body([
                'fulfilment_type' => 'delivery',
                'pickup_at' => null,
                'address_id' => $address->id,
            ]))
            ->assertOk()
            // No pin was sent either, so there is no destination at all - the
            // address id named nothing.
            ->assertJsonPath('destination.source', 'none')
            ->assertJsonPath('blockers.0.code', 'LOCATION_REQUIRED');
    }

    // -----------------------------------------------------------------
    // Placement and the tracking credential
    // -----------------------------------------------------------------

    public function test_placing_a_guest_order_records_the_contact_on_the_order(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated()
            ->assertJsonPath('order.user_id', null);

        $order = Order::firstOrFail();

        $this->assertNull($order->user_id);
        $this->assertSame('Julia Veneese', $order->guest_name);
        $this->assertSame('09171234567', $order->guest_phone);
        $this->assertSame('julia@example.test', $order->guest_email);
        $this->assertSame('350.00', $order->total_amount);
        $this->assertSame('unpaid', $order->payment_status);
    }

    public function test_the_tracking_token_is_returned_once_and_stored_only_as_a_hash(): void
    {
        $token = $this->guestCart();

        $plain = $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated()
            ->json('tracking_token');

        $this->assertIsString($plain);
        $this->assertSame(64, strlen($plain), '32 random bytes, hex encoded.');

        $order = Order::firstOrFail();

        $this->assertNotSame($plain, $order->guest_token_hash);
        $this->assertSame(hash('sha256', $plain), $order->guest_token_hash);
    }

    public function test_two_guest_orders_do_not_share_a_token(): void
    {
        $first = $this->asGuest($this->guestCart())
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated()
            ->json('tracking_token');

        $second = $this->asGuest($this->guestCart())
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated()
            ->json('tracking_token');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Order::whereNotNull('guest_token_hash')->distinct('guest_token_hash')->count());
    }

    public function test_placing_clears_the_guests_cart(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated();

        $this->asGuest($token)
            ->getJson('/api/guest/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 0);
    }

    public function test_a_guest_delivery_order_is_priced_by_the_router(): void
    {
        $token = $this->guestCart();
        $this->fakeRouting(7.4);

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body([
                'fulfilment_type' => 'delivery',
                'pickup_at' => null,
                'latitude' => 14.9700,
                'longitude' => 120.7600,
                'full_address' => 'ADD Street, Apalit',
            ]))
            ->assertCreated();

        $order = Order::firstOrFail();

        $this->assertSame('delivery', $order->order_type);
        // 3 km base plus 5 chargeable km, the same arithmetic as an account order.
        $this->assertSame('105.00', $order->delivery_fee);
        $this->assertSame('455.00', $order->total_amount);
        $this->assertSame('7.40', $order->delivery_distance_km);
    }

    public function test_an_order_cannot_be_placed_without_a_cart(): void
    {
        $this->postJson('/api/guest/orders', $this->body())
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'EMPTY_SELECTION');

        $this->assertSame(0, Order::count());
    }

    public function test_a_guest_order_needs_no_session(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // Ceilings on what anonymous traffic may spend
    // -----------------------------------------------------------------

    public function test_guest_quoting_is_capped_in_aggregate_and_not_only_per_address(): void
    {
        $token = $this->guestCart();

        config()->set('store.guest.quote_per_day', 1);

        $this->asGuest($token)
            ->postJson('/api/guest/checkout/quote', $this->body())
            ->assertOk();

        // From a different address on purpose: the ceiling that matters is the
        // shared one, because a per-IP limit cannot bound what a crowd of
        // addresses spends on somebody else's free tier.
        $this->asGuest($token)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/guest/checkout/quote', $this->body())
            ->assertStatus(429);
    }

    public function test_guest_geocoding_is_limited_per_address(): void
    {
        config()->set('store.guest.geocode_per_minute', 1);
        Http::fake();

        $this->getJson('/api/guest/geocode/search?q=Apalit')->assertOk();
        $this->getJson('/api/guest/geocode/search?q=Apalit+Pampanga')->assertStatus(429);
    }

    public function test_the_signed_in_quote_is_not_held_to_the_guest_ceiling(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food()->id])
            ->assertCreated();

        config()->set('store.guest.quote_per_day', 0);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'fulfilment_type' => 'pickup',
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
                'contact_name' => 'Account Holder',
                'contact_phone' => '09171234567',
            ])
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // FR-02.11 / FR-02.12: the one guarded endpoint with no session behind it
    // -----------------------------------------------------------------

    private function enableRecaptcha(): void
    {
        config()->set('services.recaptcha.enabled', true);
        config()->set('services.recaptcha.site_key', 'test-site-key');
        config()->set('services.recaptcha.secret_key', 'test-secret-key');
        config()->set('services.recaptcha.min_score', 0.5);
    }

    public function test_a_guest_order_without_a_recaptcha_token_is_refused(): void
    {
        $token = $this->guestCart();

        $this->enableRecaptcha();
        Http::fake();

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body())
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECAPTCHA_FAILED')
            ->assertJsonPath('reason', 'missing');

        $this->assertSame(0, Order::count());

        // Refused before Google was asked, so a script cannot make us spend a
        // verification on a request that carried nothing to verify.
        Http::assertNothingSent();
    }

    /**
     * FR-02.12 / BR-35. The OTP step-up needs a verified channel to challenge and
     * a guest has none, so the copy has to point at an account rather than invite
     * a retry that will score exactly the same.
     */
    public function test_a_guest_who_scores_low_is_sent_to_sign_in_rather_than_an_otp(): void
    {
        $token = $this->guestCart();

        $this->enableRecaptcha();
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.1,
                'action' => 'place_order',
            ], 200),
        ]);

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body(['recaptcha_token' => 'looks-automated']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECAPTCHA_FAILED')
            ->assertJsonPath('reason', 'low_score')
            ->assertJsonPath('message', 'This request looked automated. Please sign in to continue.');

        $this->assertSame(0, Order::count());
    }

    public function test_a_guest_order_with_a_good_score_goes_through(): void
    {
        $token = $this->guestCart();

        $this->enableRecaptcha();
        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'place_order',
            ], 200),
        ]);

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body(['recaptcha_token' => 'looks-human']))
            ->assertCreated();

        $this->assertSame(1, Order::count());
    }

    public function test_a_price_in_the_request_body_is_ignored(): void
    {
        $token = $this->guestCart();

        $this->asGuest($token)
            ->postJson('/api/guest/orders', $this->body([
                'total' => 1,
                'subtotal' => 1,
                'delivery_fee' => 0,
            ]))
            ->assertCreated();

        $this->assertSame('350.00', Order::firstOrFail()->total_amount);
    }
}
