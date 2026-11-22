<?php

namespace Tests\Feature;

use App\Events\MenuBroadcast;
use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Food;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\AdvanceExpiry;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\PaymentGateway;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Decided 2026-11-20: the preparation time given at acceptance, the pay-by
 * time it sets for a collection today or tomorrow, the automatic reminders,
 * the review before payment, and the To Confirm / Scheduled moves.
 */
class AdvancePreparationRuleTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-callback-token';

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 5 October 2026, 10:00 store time.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class, MenuBroadcast::class]);
        config(['services.xendit.webhook_token' => self::TOKEN]);

        Setting::put(Setting::STORE_OPENS_AT, '07:00');
        Setting::put(Setting::STORE_CLOSES_AT, '20:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function user(string $role = User::ROLE_CUSTOMER): User
    {
        $phone = '+63922000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Person',
            'role' => $role,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    /** An advance order collected at a store-time moment, e.g. '2026-10-06 12:00'. */
    private function order(User $customer, string $collectAt, array $overrides = []): Order
    {
        $food = Food::create([
            'food_name' => 'Chicken Inasal',
            'description' => 'Charcoal grilled.',
            'price' => 200,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 15,
        ]);

        $order = $this->orderWith(array_merge([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::SUBMITTED,
            'scheduled_for' => CarbonImmutable::parse($collectAt, 'Asia/Manila')->utc(),
            'subtotal' => 400,
            'total_amount' => 400,
            'total_price' => 400,
            'payment_status' => 'unpaid',
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'food_id' => $food->id,
            'quantity' => 2,
            'price' => 200,
            'unit_price' => 200,
            'subtotal' => 400,
        ]);

        return $order->fresh();
    }

    private function accept(Order $order, ?int $minutes = 60)
    {
        return $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/accept", $minutes === null ? [] : ['preparation_time' => $minutes]);
    }

    private function at(string $moment): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($moment, 'Asia/Manila')->utc());
    }

    private function storeTime(?CarbonImmutable $at): string
    {
        return $at->setTimezone('Asia/Manila')->format('Y-m-d H:i');
    }

    // -----------------------------------------------------------------
    // Accepting with a preparation time
    // -----------------------------------------------------------------

    public function test_accepting_needs_a_preparation_time_within_bounds(): void
    {
        $order = $this->order($this->user(), '2026-10-06 12:00');

        $this->accept($order, null)->assertUnprocessable()->assertJsonValidationErrors('preparation_time');
        $this->accept($order, Order::MIN_PREPARATION_MINUTES - 1)->assertJsonValidationErrors('preparation_time');
        $this->accept($order, Order::MAX_PREPARATION_MINUTES + 1)->assertJsonValidationErrors('preparation_time');

        $this->assertSame(OrderStatus::SUBMITTED, $order->fresh()->status);
    }

    public function test_a_next_day_order_is_due_its_collection_time_less_the_preparation_time(): void
    {
        $order = $this->order($this->user(), '2026-10-06 19:00');

        $this->accept($order, 60)
            ->assertOk()
            ->assertJsonPath('order.preparation_time', 60)
            ->assertJsonPath('order.pays_before_preparation', true);

        // Not 24 hours from acceptance (10:00 tomorrow): the 24-hour rule does not apply.
        $this->assertSame('2026-10-06 18:00', $this->storeTime($order->fresh()->paymentDueAt()));
    }

    public function test_a_same_day_acceptance_uses_the_same_rule(): void
    {
        $order = $this->order($this->user(), '2026-10-05 18:00');

        $this->accept($order, 90)->assertOk();

        $this->assertSame('2026-10-05 16:30', $this->storeTime($order->fresh()->paymentDueAt()));
    }

    public function test_a_later_date_keeps_the_twenty_four_hour_rule(): void
    {
        $order = $this->order($this->user(), '2026-10-07 12:00');

        $this->accept($order, 60)->assertOk()->assertJsonPath('order.pays_before_preparation', false);

        $this->assertSame('2026-10-06 10:00', $this->storeTime($order->fresh()->paymentDueAt()));
    }

    public function test_the_twenty_four_hour_rule_never_runs_past_the_collection_time(): void
    {
        // Accepted late in the evening for the day after tomorrow, morning.
        $this->at('2026-10-05 19:30');
        $order = $this->order($this->user(), '2026-10-07 08:00');

        $this->accept($order, 60)->assertOk();

        $this->assertSame('2026-10-06 19:30', $this->storeTime($order->fresh()->paymentDueAt()));
    }

    public function test_accepting_is_refused_once_the_pay_by_time_has_passed(): void
    {
        $order = $this->order($this->user(), '2026-10-05 10:45');

        $this->accept($order, 60)
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PAY_BY_PASSED');

        $this->assertSame(OrderStatus::SUBMITTED, $order->fresh()->status);

        // A shorter preparation time still leaves time to pay.
        $this->accept($order, 30)->assertOk();
    }

    public function test_a_short_window_tells_the_customer_the_minutes_left(): void
    {
        $customer = $this->user();
        $order = $this->order($customer, '2026-10-05 11:00');

        $this->accept($order, 40)->assertOk();

        $body = Notification::where('user_id', $customer->id)->latest('id')->value('body');
        $this->assertStringContainsString('Please pay within 20 minutes, by 10:20 AM today, so the store has time to prepare it.', $body);
        $this->assertStringContainsString('the store will cancel it', $body);
    }

    // -----------------------------------------------------------------
    // The sweep and late payments
    // -----------------------------------------------------------------

    public function test_the_sweep_cancels_a_next_day_order_unpaid_at_the_preparation_time(): void
    {
        $order = $this->order($this->user(), '2026-10-06 12:00');
        $this->accept($order, 120)->assertOk();

        $this->at('2026-10-06 09:59');
        $this->artisan('advance:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);

        $this->at('2026-10-06 10:00');
        $this->artisan('advance:expire-unpaid')->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        $this->assertSame(AdvanceExpiry::PAYMENT_BEFORE_PREPARATION, $fresh->cancellation_reason);
        $this->assertTrue(AdvanceExpiry::expired($fresh->status, $fresh->cancellation_reason, $fresh->cancelled_by));
    }

    public function test_a_payment_after_the_pay_by_time_is_owed_back_not_confirmed(): void
    {
        $customer = $this->user();
        $order = $this->order($customer, '2026-10-06 12:00');
        $this->accept($order, 60)->assertOk();

        // The page opens just before the deadline; Xendit keeps it open longer.
        $this->at('2026-10-06 10:55');
        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $session = app(PaymentGateway::class)->latest()->id;

        $this->at('2026-10-06 11:05');
        app(PaymentGateway::class)->pay($session);
        $this->withHeader('x-callback-token', self::TOKEN)
            ->postJson('/api/payments/xendit/webhook', ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => $session]])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        $this->assertSame(400.0, (float) $fresh->paidPayment->refund_amount);
    }

    public function test_a_payment_inside_the_grace_minutes_still_confirms(): void
    {
        $customer = $this->user();
        $order = $this->order($customer, '2026-10-06 12:00');
        $this->accept($order, 60)->assertOk();

        $this->at('2026-10-06 10:58');
        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $session = app(PaymentGateway::class)->latest()->id;

        $this->at('2026-10-06 11:01');
        app(PaymentGateway::class)->pay($session);
        $this->withHeader('x-callback-token', self::TOKEN)
            ->postJson('/api/payments/xendit/webhook', ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => $session]])
            ->assertOk();

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Reminders
    // -----------------------------------------------------------------

    public function test_the_customer_is_reminded_once_two_hours_before_the_pay_by_time(): void
    {
        $customer = $this->user();
        $order = $this->order($customer, '2026-10-08 12:00');
        $this->accept($order, 60)->assertOk(); // due 10:00 on the 6th

        $this->at('2026-10-06 07:59');
        $this->artisan('advance:remind-payment')->assertSuccessful();
        $this->assertSame(0, $this->reminders($customer));

        $this->at('2026-10-06 08:00');
        $this->artisan('advance:remind-payment')->assertSuccessful();
        $this->artisan('advance:remind-payment')->assertSuccessful();

        $this->assertSame(1, $this->reminders($customer));
        $body = Notification::where('user_id', $customer->id)->where('title', OrderNotice::DUE_SOON_TITLE)->value('body');
        $this->assertStringContainsString('Please pay by 10:00 AM today', $body);
    }

    public function test_no_reminder_when_the_whole_window_was_two_hours_or_less(): void
    {
        $customer = $this->user();
        $order = $this->order($customer, '2026-10-05 13:00');
        $this->accept($order, 60)->assertOk(); // due 12:00, two hours after acceptance

        $this->at('2026-10-05 11:00');
        $this->artisan('advance:remind-payment')->assertSuccessful();

        $this->assertSame(0, $this->reminders($customer));
    }

    public function test_a_paid_order_is_never_reminded(): void
    {
        $customer = $this->user();
        $order = $this->order($customer, '2026-10-08 12:00');
        $this->accept($order, 60)->assertOk();
        $this->payFor($order);
        $order->forceFill(['status' => OrderStatus::CONFIRMED])->save();

        $this->at('2026-10-06 09:00');
        $this->artisan('advance:remind-payment')->assertSuccessful();

        $this->assertSame(0, $this->reminders($customer));
    }

    private function reminders(User $customer): int
    {
        return Notification::where('user_id', $customer->id)
            ->where('notification_type', OrderNotice::TYPE_ADVANCE_PAYMENT)
            ->count();
    }

    // -----------------------------------------------------------------
    // To Confirm and Scheduled
    // -----------------------------------------------------------------

    private function paid(string $collectAt, string $status = OrderStatus::CONFIRMED): Order
    {
        $order = $this->order($this->user(), $collectAt, [
            'accepted_at' => now()->subDay(),
            'payment_status' => 'paid',
        ]);
        $order->forceFill(['status' => $status])->save();

        return $order->fresh();
    }

    private function step(Order $order)
    {
        return $this->actingAs($this->user(User::ROLE_ADMIN))->postJson("/api/agent/orders/{$order->id}/advance");
    }

    public function test_a_paid_order_for_today_goes_straight_into_preparing(): void
    {
        $order = $this->paid('2026-10-05 15:00');

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->getJson('/api/agent/advance-requests')
            ->assertJsonPath('scheduled.0.can_start_preparing', true)
            ->assertJsonPath('scheduled.0.can_schedule', false);

        $this->step($order)->assertOk();

        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    public function test_a_paid_order_for_a_later_day_is_scheduled_then_prepared_on_the_day(): void
    {
        $order = $this->paid('2026-10-07 15:00');

        $this->step($order)->assertOk();
        $this->assertSame(OrderStatus::SCHEDULED, $order->fresh()->status);

        $this->step($order)->assertUnprocessable()->assertJsonPath('error_code', 'ADVANCE_NOT_DUE');

        $this->at('2026-10-07 09:00');
        $this->step($order)->assertOk();
        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    public function test_a_paid_order_past_its_collection_day_can_only_be_cancelled(): void
    {
        $confirmed = $this->paid('2026-10-04 15:00');
        $scheduled = $this->paid('2026-10-04 15:00', OrderStatus::SCHEDULED);

        $this->step($confirmed)->assertUnprocessable()->assertJsonPath('error_code', 'ADVANCE_DATE_PASSED');
        $this->step($scheduled)->assertUnprocessable()->assertJsonPath('error_code', 'ADVANCE_DATE_PASSED');

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/orders/{$confirmed->id}/cancel", ['reason' => 'The customer did not come.', 'refund' => true])
            ->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $confirmed->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Review before payment, and the collection time's time zone
    // -----------------------------------------------------------------

    public function test_continuing_from_the_review_records_it_each_time(): void
    {
        $customer = $this->user();
        $order = $this->order($customer, '2026-10-08 12:00');
        $this->accept($order, 60)->assertOk();

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $this->assertNull($order->fresh()->details_confirmed_at, 'Only the review step writes it.');

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true])->assertOk();
        $first = $order->fresh()->details_confirmed_at;
        $this->assertNotNull($first);

        $this->at('2026-10-05 10:05');
        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true])->assertOk();
        $this->assertTrue($order->fresh()->details_confirmed_at->greaterThan($first));
    }

    public function test_a_collection_time_without_an_offset_is_read_as_store_time(): void
    {
        $customer = $this->user();
        $food = Food::create([
            'food_name' => 'Fries',
            'description' => 'Crisp.',
            'price' => 90,
            'thumbnail' => 'https://example.test/fries.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 5,
        ]);

        $this->actingAs($customer)->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 1, 'mode' => 'advance'])->assertCreated();

        // 2:00 PM, from a phone whose own clock is set to UTC.
        $this->actingAs($customer)
            ->postJson('/api/advance-orders/quote', ['scheduled_for' => '2026-10-06T14:00:00'])
            ->assertOk()
            ->assertJsonPath('can_submit', true);

        // And it is stored as that moment: 2:00 PM in Manila is 06:00 UTC.
        $this->actingAs($customer)
            ->postJson('/api/advance-orders', ['scheduled_for' => '2026-10-06T14:00:00'])
            ->assertCreated();

        $this->assertSame(
            '2026-10-06 14:00',
            Order::query()->latest('id')->first()->scheduled_for->setTimezone('Asia/Manila')->format('Y-m-d H:i')
        );
    }
}
