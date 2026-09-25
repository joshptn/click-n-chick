<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Discount;
use App\Models\Food;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Submitting an advance order request (BRD §3.2, BR-16a through BR-16b).
 */
class AdvanceOrderSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-morning, comfortably inside the 7am-8pm window, so "tomorrow" is
        // bookable and the submission cut-off is not in play.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Manila')->utc());

        Setting::put(Setting::STORE_OPENS_AT, '07:00');
        Setting::put(Setting::STORE_CLOSES_AT, '20:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(): User
    {
        $phone = '+63919000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'first_name' => 'Joshua',
            'last_name' => 'Pattena',
            'role' => User::ROLE_CUSTOMER,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Chicken Inasal',
            'description' => 'Charcoal grilled.',
            'price' => 200,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 15,
        ], $overrides));
    }

    private function withAdvanceCart(User $user, int $quantity = 2): array
    {
        $food = $this->food();

        $response = $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => $quantity,
            'mode' => 'advance',
        ])->assertCreated();

        return [$food, $response->json('cart_item_id')];
    }

    /** Tomorrow at noon, in store time. */
    private function tomorrowAtNoon(): string
    {
        return CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String();
    }

    // -----------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------

    public function test_a_customer_submits_an_advance_request(): void
    {
        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => $this->tomorrowAtNoon(),
        ])->assertCreated()
            ->assertJsonPath('order.status', OrderStatus::SUBMITTED)
            ->assertJsonPath('order.item_count', 2);

        $order = Order::first();

        $this->assertSame(OrderStatus::SUBMITTED, $order->status);
        $this->assertSame('pickup', $order->order_type);
        $this->assertNotNull($order->scheduled_for);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(400.0, (float) $order->total_amount);
    }

    public function test_an_advance_order_takes_no_queue_number(): void
    {
        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => $this->tomorrowAtNoon(),
        ])->assertCreated();

        $order = Order::first();

        $this->assertNull($order->queue_number);
        $this->assertNull($order->queue_date);
        $this->assertNull($order->queued_at);
    }

    public function test_submitting_empties_only_the_advance_cart(): void
    {
        $user = $this->customer();
        $food = $this->food();

        $this->actingAs($user)->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 3]);
        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 2,
            'mode' => 'advance',
        ]);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => $this->tomorrowAtNoon(),
        ])->assertCreated();

        $this->actingAs($user)->getJson('/api/cart?mode=advance')->assertJsonPath('item_count', 0);
        $this->actingAs($user)->getJson('/api/cart')->assertJsonPath('item_count', 3);
    }

    // -----------------------------------------------------------------
    // The scheduling window
    // -----------------------------------------------------------------

    public function test_today_cannot_be_booked(): void
    {
        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->setTime(18, 0)->toIso8601String(),
        ])->assertStatus(409)->assertJsonPath('error_code', 'SCHEDULE_TOO_SOON');
    }

    public function test_beyond_thirty_days_is_refused(): void
    {
        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(31)->setTime(12, 0)->toIso8601String(),
        ])->assertStatus(409)->assertJsonPath('error_code', 'SCHEDULE_TOO_FAR');
    }

    public function test_a_collection_time_before_opening_is_refused(): void
    {
        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDay()->setTime(5, 0)->toIso8601String(),
        ])->assertStatus(409)->assertJsonPath('error_code', 'SCHEDULE_BEFORE_OPENING');
    }

    public function test_a_collection_time_after_closing_is_refused(): void
    {
        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDay()->setTime(23, 0)->toIso8601String(),
        ])->assertStatus(409)->assertJsonPath('error_code', 'SCHEDULE_AFTER_CLOSING');
    }

    public function test_after_closing_tomorrow_is_no_longer_bookable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 23:30:00', 'Asia/Manila')->utc());

        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String(),
        ])->assertStatus(409)->assertJsonPath('error_code', 'SCHEDULE_TOO_SOON');

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(2)->setTime(12, 0)->toIso8601String(),
        ])->assertCreated();
    }

    public function test_a_request_may_be_submitted_while_the_store_is_closed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 04:00:00', 'Asia/Manila')->utc());

        $user = $this->customer();
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String(),
        ])->assertCreated();
    }

    public function test_an_empty_advance_cart_cannot_be_submitted(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => $this->tomorrowAtNoon(),
        ])->assertStatus(409)->assertJsonPath('error_code', 'EMPTY_SELECTION');
    }

    // -----------------------------------------------------------------
    // The statutory discount, keyed to the collection date
    // -----------------------------------------------------------------

    private function approveDiscount(User $user): void
    {
        Discount::create([
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_APPROVED,
            'verified_at' => now(),
        ]);
    }

    public function test_the_statutory_discount_applies_to_an_advance_order(): void
    {
        $user = $this->customer();
        $this->approveDiscount($user);
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => $this->tomorrowAtNoon(),
            'apply_discount' => true,
        ])->assertCreated();

        $order = Order::first();

        $this->assertSame(80.0, (float) $order->discount_amount);
        $this->assertSame(320.0, (float) $order->total_amount);
    }

    public function test_the_allowance_is_spent_on_the_collection_date_not_the_day_it_was_typed(): void
    {
        $user = $this->customer();
        $this->approveDiscount($user);
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(5)->setTime(12, 0)->toIso8601String(),
            'apply_discount' => true,
        ])->assertCreated();

        // Today's allowance is untouched: the discounted order lands in five
        // days' time, not now.
        $this->withAdvanceCart($user);

        $this->actingAs($user)->postJson('/api/advance-orders/quote', [
            'scheduled_for' => $this->tomorrowAtNoon(),
            'apply_discount' => true,
        ])->assertOk()->assertJsonPath('discount.applied', true);

        // The date already carrying a discount refuses a second one.
        $this->actingAs($user)->postJson('/api/advance-orders/quote', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(5)->setTime(16, 0)->toIso8601String(),
            'apply_discount' => true,
        ])->assertOk()
            ->assertJsonPath('discount.applied', false)
            ->assertJsonPath('blockers.0.code', 'DISCOUNT_ALREADY_USED');
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_staff_cannot_submit_an_advance_order(): void
    {
        $phone = '+639170009999';

        $agent = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();

        $this->actingAs($agent)->postJson('/api/advance-orders', [
            'scheduled_for' => $this->tomorrowAtNoon(),
        ])->assertForbidden();
    }

    public function test_the_advance_cart_is_the_one_that_is_quoted(): void
    {
        $user = $this->customer();
        $food = $this->food();

        $this->actingAs($user)->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 9]);

        $this->actingAs($user)->postJson('/api/advance-orders/quote', [
            'scheduled_for' => $this->tomorrowAtNoon(),
        ])->assertOk()
            ->assertJsonPath('item_count', 0)
            ->assertJsonPath('blockers.0.code', 'EMPTY_SELECTION');

        $this->assertSame(
            Cart::STATUS_IMMEDIATE,
            Cart::where('user_id', $user->id)->value('cart_status')
        );
    }
}
