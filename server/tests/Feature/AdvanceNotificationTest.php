<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Models\Cart;
use App\Models\Food;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Notifications across the advance-order lifecycle (UC-NOTIF-010 … 014).
 *
 * These assert on the stored rows rather than only on broadcasts, because a
 * broadcast is not replayed. The advance flow is built out of long gaps where
 * nobody is watching - a request sent at 11pm answered the next morning, a
 * booking a month old coming due - so a notice that exists only on the wire is
 * a notice the recipient never gets.
 */
class AdvanceNotificationTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Manila')->utc());

        // The suite runs the queue inline, so an unfaked broadcast is a real HTTP
        // call to a Reverb server that is not running. Everything the announcer
        // sends is wrapped in its own try/catch and merely slow, but the reminder
        // endpoint stores its notification directly - there the failed call came
        // back as a 500, and whether it did depended on how fast the connection
        // gave up. Faking it here asserts nothing; it keeps the network out.
        Event::fake([NotificationBroadcast::class]);

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
        $phone = '+63919000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'role' => $role,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function food(): Food
    {
        return Food::create([
            'food_name' => 'Chicken Inasal',
            'description' => 'Charcoal grilled.',
            'price' => 200,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 15,
        ]);
    }

    private function tomorrowAtNoon(): string
    {
        return CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String();
    }

    /** Submit a real request through the endpoint, so the wiring is under test. */
    private function submitRequest(User $customer): Order
    {
        $food = $this->food();

        $this->actingAs($customer)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 2,
            'mode' => 'advance',
        ])->assertCreated();

        $this->actingAs($customer)->postJson('/api/advance-orders', [
            'scheduled_for' => $this->tomorrowAtNoon(),
        ])->assertCreated();

        return Order::latest('id')->first();
    }

    private function bodyFor(int $userId, ?string $type = null): ?string
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->when($type !== null, fn ($query) => $query->where('notification_type', $type))
            ->latest('id')
            ->value('body');
    }

    // -----------------------------------------------------------------
    // Submission (UC-NOTIF-001, UC-NOTIF-011)
    // -----------------------------------------------------------------

    public function test_submitting_tells_the_customer_their_request_is_with_the_store(): void
    {
        $customer = $this->user();
        $order = $this->submitRequest($customer);

        $body = $this->bodyFor($customer->id);

        // Not "your order has been placed" - nothing is placed yet, and saying
        // so would imply the store has agreed to make it.
        $this->assertStringContainsString('is with the store', $body);
        $this->assertStringContainsString('reviewed it', $body);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'notification_type' => OrderNotice::TYPE_ADVANCE,
            'title' => 'Request sent',
        ]);
    }

    public function test_submitting_alerts_every_store_agent(): void
    {
        $agentOne = $this->user(User::ROLE_ADMIN);
        $agentTwo = $this->user(User::ROLE_ADMIN);
        $customer = $this->user();

        $order = $this->submitRequest($customer);

        foreach ([$agentOne, $agentTwo] as $agent) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $agent->id,
                'order_id' => $order->id,
                'notification_type' => OrderNotice::TYPE_ADVANCE_REQUEST,
            ]);
        }

        // The wording has to be actionable: which order, for when, how big.
        $body = $this->bodyFor($agentOne->id, OrderNotice::TYPE_ADVANCE_REQUEST);

        $this->assertStringContainsString("Request #{$order->id}", $body);
        $this->assertStringContainsString('2 item(s)', $body);
        $this->assertStringContainsString('accepting or rejecting', $body);
    }

    public function test_a_suspended_agent_is_not_notified(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $agent->forceFill(['account_status' => 'suspended'])->save();

        $this->submitRequest($this->user());

        $this->assertDatabaseMissing('notifications', ['user_id' => $agent->id]);
    }

    public function test_the_store_manager_is_not_asked_to_decide_a_request(): void
    {
        // UC-NOTIF-011 names the Store Agent. The manager hears about orders
        // coming due, not about requests waiting on a decision.
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->submitRequest($this->user());

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $manager->id,
            'notification_type' => OrderNotice::TYPE_ADVANCE_REQUEST,
        ]);
    }

    public function test_the_notification_is_pushed_and_not_merely_stored(): void
    {
        Event::fake([NotificationBroadcast::class]);

        $agent = $this->user(User::ROLE_ADMIN);
        $customer = $this->user();

        $this->submitRequest($customer);

        Event::assertDispatched(
            NotificationBroadcast::class,
            fn (NotificationBroadcast $event) => $event->userId === $customer->id
        );

        Event::assertDispatched(
            NotificationBroadcast::class,
            fn (NotificationBroadcast $event) => $event->userId === $agent->id
        );
    }

    // -----------------------------------------------------------------
    // The decision (UC-NOTIF-010)
    // -----------------------------------------------------------------

    public function test_acceptance_tells_the_customer_when_to_pay_by(): void
    {
        $customer = $this->user();
        $order = $this->submitRequest($customer);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertOk();

        $body = $this->bodyFor($customer->id, OrderNotice::TYPE_ADVANCE);

        $this->assertStringContainsString('accepted your advance order', $body);
        // BR-16f is 24 hours from acceptance, so the deadline is a real time the
        // customer can act on rather than "soon".
        $this->assertStringContainsString('Pay by', $body);
        $this->assertStringContainsString('6 Oct', $body);
    }

    public function test_only_one_notification_is_sent_for_the_accept_transition(): void
    {
        // accept() walks the order through `accepted` into `awaiting_payment` in
        // one transaction. The customer should hear once, not twice.
        $customer = $this->user();
        $order = $this->submitRequest($customer);

        Notification::where('user_id', $customer->id)->delete();

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertOk();

        $this->assertSame(1, Notification::where('user_id', $customer->id)->count());
    }

    public function test_rejection_carries_the_agents_reason(): void
    {
        $customer = $this->user();
        $order = $this->submitRequest($customer);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/reject", [
                'reason' => 'We are closed for a family event that day',
            ])
            ->assertOk();

        $body = $this->bodyFor($customer->id, OrderNotice::TYPE_ADVANCE);

        $this->assertStringContainsString('could not take on your advance order', $body);
        $this->assertStringContainsString('We are closed for a family event that day.', $body);
        $this->assertStringContainsString('Nothing was charged.', $body);
    }

    // -----------------------------------------------------------------
    // Scheduling and the rest of the chain (UC-NOTIF-004)
    // -----------------------------------------------------------------

    public function test_the_customer_is_told_when_the_order_is_booked_in(): void
    {
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::CONFIRMED, $customer);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertOk();

        $this->assertSame(OrderStatus::SCHEDULED, $order->fresh()->status);
        $this->assertStringContainsString('scheduled for', $this->bodyFor($customer->id));
    }

    public function test_preparation_onwards_reads_like_any_other_pickup_order(): void
    {
        // Once the kitchen starts there is nothing advance about it any more,
        // and the customer needs the same words as anyone else.
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::SCHEDULED, $customer, paid: true);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertOk();

        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
        $this->assertStringContainsString('is being prepared now', $this->bodyFor($customer->id));
    }

    // -----------------------------------------------------------------
    // Cancellation (UC-NOTIF-012)
    // -----------------------------------------------------------------

    public function test_a_shop_cancellation_says_who_did_it_and_what_happens_to_the_money(): void
    {
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::SCHEDULED, $customer, paid: true);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'A pipe burst in the kitchen'])
            ->assertOk();

        $body = $this->bodyFor($customer->id);

        $this->assertStringContainsString('The store cancelled', $body);
        $this->assertStringContainsString('A pipe burst in the kitchen.', $body);
        $this->assertStringContainsString('₱400.00 will be refunded', $body);
    }

    public function test_a_customer_cancelling_is_not_told_the_store_did_it(): void
    {
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::SCHEDULED, $customer, paid: true);

        $this->actingAs($customer)->postJson("/api/order/{$order->id}/cancel")->assertOk();

        $body = $this->bodyFor($customer->id);

        $this->assertStringNotContainsString('The store cancelled', $body);
        $this->assertStringContainsString('₱400.00 will be refunded', $body);
    }

    public function test_cancelling_an_unpaid_request_promises_no_refund(): void
    {
        $customer = $this->user();
        $order = $this->submitRequest($customer);

        $this->actingAs($customer)->postJson("/api/order/{$order->id}/cancel")->assertOk();

        $this->assertStringContainsString('nothing to refund', $this->bodyFor($customer->id));
    }

    public function test_staff_are_told_when_a_customer_calls_off_a_booked_order(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::SCHEDULED, $customer, paid: true);

        $this->actingAs($customer)->postJson("/api/order/{$order->id}/cancel")->assertOk();

        $body = $this->bodyFor($agent->id, OrderNotice::TYPE_ADVANCE);

        $this->assertStringContainsString("The customer cancelled order #{$order->id}", $body);
        $this->assertStringContainsString('₱400.00 is owed back', $body);
    }

    public function test_an_immediate_cancellation_does_not_pester_staff(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $customer = $this->user();

        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::CONFIRMED,
            'total_price' => 300,
            'total_amount' => 300,
            'payment_status' => 'paid',
        ]);

        $this->actingAs($customer)->postJson("/api/order/{$order->id}/cancel")->assertOk();

        // It is already on their queue screen; an inbox row would be noise.
        $this->assertDatabaseMissing('notifications', ['user_id' => $agent->id]);
    }

    public function test_expiring_an_unpaid_request_tells_the_customer_why(): void
    {
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::AWAITING_PAYMENT, $customer);

        $order->forceFill(['accepted_at' => CarbonImmutable::now()->subHours(25)])->save();

        $this->artisan('advance:expire-unpaid')->assertSuccessful();

        $body = $this->bodyFor($customer->id);

        $this->assertStringContainsString('Payment was not received', $body);
        $this->assertStringContainsString('nothing to refund', $body);
    }

    // -----------------------------------------------------------------
    // The payment nudge (UC-NOTIF-013)
    // -----------------------------------------------------------------

    public function test_an_agent_can_chase_an_unpaid_accepted_request(): void
    {
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::AWAITING_PAYMENT, $customer);
        $order->forceFill(['accepted_at' => CarbonImmutable::now()->subHour()])->save();

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/remind")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'notification_type' => OrderNotice::TYPE_ADVANCE_PAYMENT,
        ]);

        $this->assertStringContainsString(
            'is not paid yet',
            $this->bodyFor($customer->id, OrderNotice::TYPE_ADVANCE_PAYMENT)
        );
    }

    public function test_the_same_customer_is_not_chased_twice_in_an_hour(): void
    {
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::AWAITING_PAYMENT, $customer);

        $first = $this->user(User::ROLE_ADMIN);
        $second = $this->user(User::ROLE_ADMIN);

        $this->actingAs($first)->postJson("/api/agent/advance-requests/{$order->id}/remind")->assertOk();

        // A different agent, because the cooldown protects the customer rather
        // than limiting any one member of staff.
        $this->actingAs($second)
            ->postJson("/api/agent/advance-requests/{$order->id}/remind")
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'REMINDER_TOO_SOON');

        $this->assertSame(
            1,
            Notification::where('notification_type', OrderNotice::TYPE_ADVANCE_PAYMENT)->count()
        );
    }

    public function test_a_paid_order_cannot_be_chased(): void
    {
        $order = $this->advanceOrderAt(OrderStatus::SCHEDULED, $this->user(), paid: true);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/remind")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ALREADY_PAID');
    }

    public function test_a_request_the_store_has_not_answered_cannot_be_chased(): void
    {
        // There is nothing to pay for until the store has accepted it.
        $order = $this->submitRequest($this->user());

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/remind")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_AWAITING_PAYMENT');
    }

    public function test_a_customer_cannot_send_themselves_a_reminder(): void
    {
        $customer = $this->user();
        $order = $this->advanceOrderAt(OrderStatus::AWAITING_PAYMENT, $customer);

        $this->actingAs($customer)
            ->postJson("/api/agent/advance-requests/{$order->id}/remind")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Due today (UC-NOTIF-014)
    // -----------------------------------------------------------------

    public function test_staff_are_told_about_an_order_due_today(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $order = $this->advanceOrderAt(OrderStatus::SCHEDULED, $this->user(), paid: true, dueIn: '3 hours');

        $this->artisan('advance:notify-due')->assertSuccessful();

        // Both roles here: the manager needs to know what the day holds.
        foreach ([$agent, $manager] as $member) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $member->id,
                'order_id' => $order->id,
                'notification_type' => OrderNotice::TYPE_ADVANCE_DUE,
            ]);
        }
    }

    public function test_running_hourly_does_not_notify_twice(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $this->advanceOrderAt(OrderStatus::SCHEDULED, $this->user(), paid: true, dueIn: '3 hours');

        $this->artisan('advance:notify-due')->assertSuccessful();
        $this->artisan('advance:notify-due')->assertSuccessful();
        $this->artisan('advance:notify-due')->assertSuccessful();

        $this->assertSame(
            1,
            Notification::where('user_id', $agent->id)
                ->where('notification_type', OrderNotice::TYPE_ADVANCE_DUE)
                ->count()
        );
    }

    public function test_nothing_is_sent_before_the_store_opens(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 05:30:00', 'Asia/Manila')->utc());

        $agent = $this->user(User::ROLE_ADMIN);
        $this->advanceOrderAt(OrderStatus::SCHEDULED, $this->user(), paid: true, dueIn: '8 hours');

        $this->artisan('advance:notify-due')
            ->expectsOutputToContain('Before opening')
            ->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['user_id' => $agent->id]);
    }

    public function test_the_alert_follows_the_stores_own_opening_time(): void
    {
        // 05:30 is before the default 07:00 but after an early opening, so the
        // sweep has to read the setting rather than a time of its own.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 05:30:00', 'Asia/Manila')->utc());
        Setting::put(Setting::STORE_OPENS_AT, '05:00');

        $agent = $this->user(User::ROLE_ADMIN);
        $order = $this->advanceOrderAt(OrderStatus::SCHEDULED, $this->user(), paid: true, dueIn: '8 hours');

        $this->artisan('advance:notify-due')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'order_id' => $order->id,
            'notification_type' => OrderNotice::TYPE_ADVANCE_DUE,
        ]);
    }

    public function test_an_order_due_tomorrow_is_left_alone(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $this->advanceOrderAt(OrderStatus::SCHEDULED, $this->user(), paid: true, dueIn: '2 days');

        $this->artisan('advance:notify-due')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $agent->id,
            'notification_type' => OrderNotice::TYPE_ADVANCE_DUE,
        ]);
    }

    public function test_an_unpaid_order_due_today_is_flagged_as_unpreparable(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $this->advanceOrderAt(OrderStatus::CONFIRMED, $this->user(), dueIn: '4 hours');

        $this->artisan('advance:notify-due')->assertSuccessful();

        // BR-16g: the agent must not discover at the counter that they cannot
        // legally start this one.
        $this->assertStringContainsString(
            'still unpaid',
            $this->bodyFor($agent->id, OrderNotice::TYPE_ADVANCE_DUE)
        );
    }

    public function test_the_dry_run_sends_nothing(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $this->advanceOrderAt(OrderStatus::SCHEDULED, $this->user(), paid: true, dueIn: '3 hours');

        $this->artisan('advance:notify-due --dry-run')
            ->expectsOutputToContain('would be announced')
            ->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['user_id' => $agent->id]);
    }

    /** An advance order parked at a given status, without going through checkout. */
    private function advanceOrderAt(
        string $status,
        User $customer,
        bool $paid = false,
        string $dueIn = '5 days',
    ): Order {
        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::SUBMITTED,
            'scheduled_for' => CarbonImmutable::now()->add($dueIn),
            'subtotal' => 400,
            'total_price' => 400,
            'total_amount' => 400,
            'payment_status' => $paid ? 'paid' : 'unpaid',
            'accepted_at' => CarbonImmutable::now()->subHours(2),
        ]);

        $order->forceFill(['status' => $status])->save();

        return $order->fresh();
    }

    // -----------------------------------------------------------------
    // The inbox the bell reads
    // -----------------------------------------------------------------

    public function test_the_inbox_survives_the_browser_being_closed(): void
    {
        // The point of storing these: an advance request answered while the
        // customer is offline must still be there when they come back.
        $customer = $this->user();
        $order = $this->submitRequest($customer);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertOk();

        $response = $this->actingAs($customer)->getJson('/api/notifications')->assertOk();

        $this->assertCount(2, $response->json('data'), 'the request and its answer');
        $this->assertSame($order->id, $response->json('data.0.order_id'));
        $this->assertFalse($response->json('data.0.is_read'));
    }

    public function test_the_whole_inbox_can_be_marked_read_in_one_request(): void
    {
        $customer = $this->user();
        $order = $this->submitRequest($customer);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertOk();

        $this->actingAs($customer)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('marked', 2);

        $this->assertSame(
            0,
            Notification::where('user_id', $customer->id)->where('is_read', false)->count()
        );
    }

    public function test_marking_read_leaves_other_peoples_notifications_alone(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $customer = $this->user();

        $this->submitRequest($customer);

        $this->actingAs($customer)->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(
            1,
            Notification::where('user_id', $agent->id)->where('is_read', false)->count()
        );
    }

    public function test_the_advance_cart_is_not_left_behind(): void
    {
        // Guards the fixture above: submitRequest goes through the real endpoint,
        // so a stale advance cart would make later assertions lie.
        $customer = $this->user();
        $this->submitRequest($customer);

        $this->assertSame(
            0,
            Cart::where('user_id', $customer->id)->where('cart_status', Cart::STATUS_ADVANCE)->first()
                ?->items()->count() ?? 0
        );
    }
}
