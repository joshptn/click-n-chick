<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Discount;
use App\Models\Food;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 2 of checkout: the statutory Senior Citizen / PWD discount.
 *
 * The rules under test are the ones with legal or financial consequences:
 * it goes on one unit of the highest-priced dish marked for it, to the centavo
 * (FR-05.4), food only and never the delivery fee (BR-10), it is spent at
 * most once per calendar day in Manila time (BR-09), it never applies itself,
 * and the rate is whatever the Store Manager has configured right now, floored
 * at the statutory 20% (BR-27/BR-34).
 */
class CheckoutDiscountTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);
        Cache::flush();

        config()->set('services.routing.openrouteservice.api_key', 'test-key');

        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [['summary' => ['distance' => 2.5, 'duration' => 300]]],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(): User
    {
        $phone = '+63917400'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'first_name' => 'Julia',
            'last_name' => 'Veneese',
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    /** @return array{0: User, 1: int} */
    private function customerWithCart(int $price = 400): array
    {
        $user = $this->customer();

        $food = Food::create([
            'food_name' => 'Herb Roasted Chicken',
            'description' => 'Whole.',
            'price' => $price,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'is_discountable' => true,
            'prep_time' => 20,
        ]);

        $lineId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 1])
            ->assertCreated()
            ->json('cart_item_id');

        return [$user, $lineId];
    }

    private function dish(string $name, int $price, bool $marked): Food
    {
        return Food::create([
            'food_name' => $name,
            'description' => 'A dish.',
            'price' => $price,
            'thumbnail' => 'https://example.test/dish.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'is_discountable' => $marked ? true : null,
            'prep_time' => 15,
        ]);
    }

    private function addToCart(User $user, Food $food, int $quantity = 1): void
    {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => $quantity])
            ->assertCreated();
    }

    private function approve(User $user, string $type = Discount::TYPE_PWD): Discount
    {
        return Discount::create([
            'user_id' => $user->id,
            'discount_type' => $type,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_APPROVED,
            'verified_at' => now(),
        ]);
    }

    private function body(array $overrides = []): array
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
    // It never applies itself (BR-09 makes silent use expensive)
    // -----------------------------------------------------------------

    public function test_an_eligible_customer_is_not_discounted_unless_they_ask(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body())
            ->assertOk()
            ->assertJsonPath('discount.eligible', true)
            ->assertJsonPath('discount.can_apply', true)
            // Offered, priced, and not taken. The day's one use is the
            // customer's to spend.
            ->assertJsonPath('discount.applied', false)
            // A ₱400 dish: ₱42.86 VAT off first, then 20% of ₱357.14.
            ->assertJsonPath('discount.available_amount', 114.29)
            ->assertJsonPath('discount.vat_exempt_amount', 42.86)
            ->assertJsonPath('discount.percentage_amount', 71.43)
            ->assertJsonPath('discount.item.food_name', 'Herb Roasted Chicken')
            ->assertJsonPath('discount.amount', 0)
            ->assertJsonPath('total', 400);
    }

    public function test_asking_for_it_applies_it(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.applied', true)
            ->assertJsonPath('discount.amount', 114.29)
            ->assertJsonPath('discount.type_label', 'PWD')
            ->assertJsonPath('total', 285.71)
            ->assertJsonPath('can_place', true);
    }

    // -----------------------------------------------------------------
    // FR-05.4: one unit of the highest-priced dish marked for it
    // -----------------------------------------------------------------

    /** The client's own example: only one spaghetti is discounted. */
    public function test_only_one_unit_of_the_marked_dish_is_discounted(): void
    {
        $user = $this->customer();
        $this->approve($user, Discount::TYPE_SENIOR);

        $this->addToCart($user, $this->dish('Chicken Spaghetti', 180, true), 2);
        $this->addToCart($user, $this->dish('Herb Chicken', 250, false));
        $this->addToCart($user, $this->dish('Regular Fries', 70, false));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('subtotal', 680)
            ->assertJsonPath('discount_base', 180)
            ->assertJsonPath('discount.item.food_name', 'Chicken Spaghetti')
            ->assertJsonPath('discount.item.price', 180)
            // ₱160.71 before VAT: ₱19.29 VAT exemption + ₱32.14 at 20%,
            // ₱51.43 off, to the centavo.
            ->assertJsonPath('discount.vat_exempt_amount', 19.29)
            ->assertJsonPath('discount.percentage_amount', 32.14)
            ->assertJsonPath('discount.amount', 51.43)
            ->assertJsonPath('total', 628.57);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body(['apply_discount' => true]))
            ->assertCreated();

        $order = Order::firstOrFail();

        $this->assertSame('51.43', $order->discount_amount);
        $this->assertSame('19.29', $order->vat_exempt_amount);
        $this->assertSame('628.57', $order->total_amount);
    }

    public function test_the_highest_priced_marked_dish_takes_it(): void
    {
        $user = $this->customer();
        $this->approve($user);

        $this->addToCart($user, $this->dish('Chicken Spaghetti', 180, true));
        $this->addToCart($user, $this->dish('2 pcs. Fried Chicken', 210, true));
        // Dearer, but not marked.
        $this->addToCart($user, $this->dish('Herb Chicken', 360, false));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.item.food_name', '2 pcs. Fried Chicken')
            // ₱187.50 before VAT: ₱22.50 + ₱37.50 = ₱60.
            ->assertJsonPath('discount.vat_exempt_amount', 22.5)
            ->assertJsonPath('discount.percentage_amount', 37.5)
            ->assertJsonPath('discount.amount', 60)
            ->assertJsonPath('total', 690);
    }

    public function test_an_order_with_no_marked_dish_has_nothing_to_discount(): void
    {
        $user = $this->customer();
        $this->approve($user);

        $this->addToCart($user, $this->dish('Herb Chicken', 250, false));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body())
            ->assertOk()
            ->assertJsonPath('discount.eligible', true)
            ->assertJsonPath('discount.can_apply', false)
            ->assertJsonPath('discount.item', null)
            ->assertJsonPath('discount.available_amount', 0)
            ->assertJsonPath('can_place', true);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.applied', false)
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'DISCOUNT_NO_ELIGIBLE_ITEM');

        // Placed without it, the day's use is still there.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body())
            ->assertCreated();

        $this->addToCart($user, $this->dish('Chicken Spaghetti', 180, true));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.used_today', false)
            ->assertJsonPath('discount.applied', true);
    }

    // -----------------------------------------------------------------
    // BR-10: food only, never the delivery fee
    // -----------------------------------------------------------------

    public function test_the_discount_comes_off_the_subtotal_and_not_the_delivery_fee(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('subtotal', 400)
            ->assertJsonPath('delivery_fee', 55)
            ->assertJsonPath('discount.amount', 114.29)
            // 400 - 114.29, then the untouched fee. Discounting the fee too
            // would be unlawful.
            ->assertJsonPath('total', 340.71);
    }

    // -----------------------------------------------------------------
    // BR-27 / BR-34: the configured rate, floored at the statutory 20%
    // -----------------------------------------------------------------

    public function test_it_uses_the_rate_configured_right_now(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        Setting::put(Setting::DISCOUNT_PERCENTAGE, 25);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.percentage', 25)
            // ₱42.86 VAT, then 25% of ₱357.14.
            ->assertJsonPath('discount.percentage_amount', 89.29)
            ->assertJsonPath('discount.amount', 132.15)
            ->assertJsonPath('total', 267.85);
    }

    /**
     * A rate below the statutory floor cannot reach the customer even if it
     * somehow reaches the settings table - by seed, migration, or a direct
     * database edit, none of which pass through validation.
     */
    public function test_a_rate_below_the_statutory_floor_is_clamped(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        Setting::put(Setting::DISCOUNT_PERCENTAGE, 5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.percentage', 20)
            ->assertJsonPath('discount.amount', 114.29);
    }

    // -----------------------------------------------------------------
    // Entitlement states
    // -----------------------------------------------------------------

    public function test_a_customer_with_no_claim_is_offered_nothing(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body())
            ->assertOk()
            ->assertJsonPath('discount.status', 'none')
            ->assertJsonPath('discount.eligible', false)
            ->assertJsonPath('discount.available_amount', 0)
            // And is not blocked by it: most customers are in this state.
            ->assertJsonPath('can_place', true);
    }

    public function test_a_pending_claim_is_reported_as_pending_and_earns_nothing_yet(): void
    {
        [$user] = $this->customerWithCart();

        Discount::create([
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_PENDING,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body())
            ->assertOk()
            ->assertJsonPath('discount.status', 'pending')
            ->assertJsonPath('discount.eligible', false)
            ->assertJsonPath('can_place', true);
    }

    public function test_a_rejected_claim_is_distinguished_from_never_having_applied(): void
    {
        [$user] = $this->customerWithCart();

        Discount::create([
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_REJECTED,
            'rejection_reason' => 'Photo unreadable.',
        ]);

        // The two need different words on screen - "apply" versus "try again".
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body())
            ->assertOk()
            ->assertJsonPath('discount.status', 'rejected')
            ->assertJsonPath('discount.eligible', false);
    }

    /** Claiming what you are not entitled to is refused, never silently dropped. */
    public function test_an_ineligible_customer_asking_for_it_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.applied', false)
            ->assertJsonPath('discount.amount', 0)
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'DISCOUNT_NOT_ELIGIBLE');
    }

    // -----------------------------------------------------------------
    // BR-09: once per calendar day, Asia/Manila
    // -----------------------------------------------------------------

    public function test_a_second_discounted_order_the_same_day_is_refused(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body(['apply_discount' => true]))
            ->assertCreated();

        $this->assertSame('114.29', Order::firstOrFail()->discount_amount);

        // Same day, second basket.
        [$user2] = [$user];
        $this->actingAs($user2, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => Food::first()->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($user2, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.used_today', true)
            ->assertJsonPath('discount.can_apply', false)
            ->assertJsonPath('blockers.0.code', 'DISCOUNT_ALREADY_USED');
    }

    /**
     * A cancelled discounted order still spends the day - the benefit is
     * consumed by using it, not by the order succeeding.
     */
    public function test_a_cancelled_discounted_order_still_consumes_the_day(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body(['apply_discount' => true]))
            ->assertCreated();

        Order::firstOrFail()->forceFill(['status' => 'cancelled'])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => Food::first()->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.used_today', true);
    }

    /** Calendar day in Manila, not a rolling 24 hours. */
    public function test_the_limit_resets_on_the_next_manila_day(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body(['apply_discount' => true]))
            ->assertCreated();

        // 23:00 the same evening: still spent.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 23:00', 'Asia/Manila')->utc());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => Food::first()->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body(['apply_discount' => true]))
            ->assertOk()
            ->assertJsonPath('discount.used_today', true);

        // Half past midnight Manila time is a new day, even though barely two
        // hours have passed.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 00:30', 'Asia/Manila')->utc());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->body([
                'apply_discount' => true,
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->addHours(9)->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonPath('discount.used_today', false)
            ->assertJsonPath('discount.applied', true);
    }

    public function test_placing_a_second_discounted_order_is_refused_even_if_the_quote_was_stale(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body(['apply_discount' => true]))
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => Food::first()->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body(['apply_discount' => true]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'DISCOUNT_ALREADY_USED');

        $this->assertSame(1, Order::count(), 'The second order must not have been written.');
    }

    // -----------------------------------------------------------------
    // What lands on the order
    // -----------------------------------------------------------------

    public function test_the_order_records_the_discount_it_was_given(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user, Discount::TYPE_SENIOR);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody(['apply_discount' => true]))
            ->assertCreated();

        $order = Order::firstOrFail();

        $this->assertSame('400.00', $order->subtotal);
        // Everything taken off, and the VAT part of it on its own.
        $this->assertSame('114.29', $order->discount_amount);
        $this->assertSame('42.86', $order->vat_exempt_amount);
        $this->assertSame('55.00', $order->delivery_fee);
        $this->assertSame('340.71', $order->total_amount);
    }

    /** The client asks; it never states the amount. */
    public function test_a_discount_amount_in_the_request_body_is_ignored(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body([
                'apply_discount' => true,
                'discount_amount' => 399,
                'vat_exempt_amount' => 399,
            ]))
            ->assertCreated();

        $this->assertSame('114.29', Order::firstOrFail()->discount_amount);
        $this->assertSame('42.86', Order::firstOrFail()->vat_exempt_amount);
    }

    public function test_an_undiscounted_order_records_zero(): void
    {
        [$user] = $this->customerWithCart();
        $this->approve($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->body())
            ->assertCreated();

        $this->assertSame('0.00', Order::firstOrFail()->discount_amount);
    }
}
