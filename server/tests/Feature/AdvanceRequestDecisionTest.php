<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Store Agent accepting or rejecting an advance request (UC-ADV-006/007).
 *
 * Endpoints only - there is no staff UI yet.
 */
class AdvanceRequestDecisionTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    private function user(string $role): User
    {
        $phone = '+63920000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'first_name' => 'Test',
            'last_name' => ucfirst(str_replace('_', ' ', $role)),
            'role' => $role,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function advanceOrder(User $customer, array $overrides = []): Order
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

        $order = Order::create(array_merge([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::SUBMITTED,
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(3)->setTime(12, 0),
            'subtotal' => 400,
            'discount_amount' => 0,
            'delivery_fee' => 0,
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

    /** An immediate order, for the tests that check the two are kept apart. */
    private function immediateOrder(User $customer): Order
    {
        return Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 200,
            'total_amount' => 200,
            'total_price' => 200,
            'payment_status' => 'unpaid',
        ]);
    }

    // -----------------------------------------------------------------
    // The list
    // -----------------------------------------------------------------

    public function test_the_agent_sees_requests_grouped_by_what_they_need(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $this->advanceOrder($customer);
        $this->advanceOrder($customer, ['status' => OrderStatus::AWAITING_PAYMENT]);
        $this->advanceOrder($customer, [
            'status' => OrderStatus::SCHEDULED,
            'payment_status' => 'paid',
        ]);
        $this->immediateOrder($customer);

        $this->actingAs($agent)->getJson('/api/agent/advance-requests')
            ->assertOk()
            ->assertJsonPath('summary.awaiting_decision', 1)
            ->assertJsonPath('summary.awaiting_payment', 1)
            ->assertJsonPath('summary.scheduled', 1);
    }

    public function test_orders_due_today_are_called_out(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $this->advanceOrder($customer, [
            'status' => OrderStatus::SCHEDULED,
            'payment_status' => 'paid',
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->setTime(15, 0),
        ]);

        $this->advanceOrder($customer, [
            'status' => OrderStatus::SCHEDULED,
            'payment_status' => 'paid',
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(4)->setTime(15, 0),
        ]);

        $this->actingAs($agent)->getJson('/api/agent/advance-requests')
            ->assertOk()
            ->assertJsonPath('summary.scheduled', 2)
            ->assertJsonPath('summary.due_today', 1);
    }

    public function test_an_advance_request_carries_no_queue_number(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->actingAs($agent)->getJson("/api/agent/advance-requests/{$order->id}")
            ->assertOk()
            ->assertJsonMissingPath('order.queue_number')
            ->assertJsonPath('order.is_paid', false)
            ->assertJsonPath('order.item_count', 2);
    }

    public function test_an_immediate_order_is_not_an_advance_request(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->immediateOrder($customer);

        $this->actingAs($agent)->getJson("/api/agent/advance-requests/{$order->id}")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Accepting
    // -----------------------------------------------------------------

    public function test_accepting_makes_the_request_payable(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath('order.status', OrderStatus::AWAITING_PAYMENT);

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->closed_at);
    }

    public function test_a_request_cannot_be_accepted_twice(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/accept")->assertOk();

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ALREADY_ANSWERED');
    }

    // -----------------------------------------------------------------
    // Rejecting
    // -----------------------------------------------------------------

    public function test_rejecting_closes_the_request_with_a_reason(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/reject", [
            'reason' => 'We cannot produce that many on a Sunday.',
        ])->assertOk()->assertJsonPath('order.status', OrderStatus::REJECTED);

        $fresh = $order->fresh();

        $this->assertSame(OrderStatus::REJECTED, $fresh->status);
        $this->assertSame('We cannot produce that many on a Sunday.', $fresh->cancellation_reason);
        $this->assertSame((int) $agent->id, (int) $fresh->cancelled_by);
        $this->assertSame(0.0, (float) $fresh->refund_owed);
        $this->assertNotNull($fresh->closed_at);
    }

    public function test_a_rejection_must_say_why(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_an_answered_request_cannot_be_rejected(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer, ['status' => OrderStatus::AWAITING_PAYMENT]);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/reject", [
            'reason' => 'Too late now.',
        ])->assertStatus(422)->assertJsonPath('error_code', 'ALREADY_ANSWERED');
    }

    // -----------------------------------------------------------------
    // Who decides
    // -----------------------------------------------------------------

    public function test_the_store_manager_can_see_the_list_but_not_decide(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->actingAs($manager)->getJson('/api/agent/advance-requests')->assertOk();

        $this->actingAs($manager)->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertForbidden();

        $this->actingAs($manager)->postJson("/api/agent/advance-requests/{$order->id}/reject", [
            'reason' => 'Not my call.',
        ])->assertForbidden();
    }

    public function test_a_customer_cannot_decide_their_own_request(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $order = $this->advanceOrder($customer);

        $this->actingAs($customer)->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // BR-16g - an unpaid order never reaches the kitchen
    // -----------------------------------------------------------------

    public function test_an_unpaid_scheduled_order_cannot_be_prepared(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::SCHEDULED,
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($agent)->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ADVANCE_UNPAID');

        $this->assertSame(OrderStatus::SCHEDULED, $order->fresh()->status);
    }

    public function test_a_paid_scheduled_order_moves_into_preparing_on_its_collection_date(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);
        $collectAt = CarbonImmutable::now('Asia/Manila')->setTime(12, 0);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::SCHEDULED,
            'payment_status' => 'paid',
            'scheduled_for' => $collectAt,
        ]);

        $stored = $order->fresh()->getRawOriginal('scheduled_for');

        $this->actingAs($agent)->postJson("/api/agent/orders/{$order->id}/advance")->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PREPARING, $fresh->status);
        // The date check must not move the stored collection time.
        $this->assertSame($stored, $fresh->getRawOriginal('scheduled_for'));
    }

    public function test_a_request_past_its_collection_date_cannot_be_accepted(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer, [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->subDays(2)->setTime(12, 0),
        ]);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ADVANCE_DATE_PASSED');

        $this->assertSame(OrderStatus::SUBMITTED, $order->fresh()->status);

        // Declining it is still open, so the customer hears back.
        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/reject", [
            'reason' => 'That date has passed - please choose another.',
        ])->assertOk();
    }

    /** UC-ADV-012: the kitchen starts it on the day, not when someone happens to click. */
    public function test_a_scheduled_order_is_not_prepared_before_its_collection_date(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::SCHEDULED,
            'payment_status' => 'paid',
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDay()->setTime(10, 0),
        ]);

        $this->actingAs($agent)->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ADVANCE_NOT_DUE');

        $this->assertSame(OrderStatus::SCHEDULED, $order->fresh()->status);
    }

    /** BRD 3.2: only the customer's payment moves an accepted request on. */
    public function test_an_unpaid_accepted_request_cannot_be_stepped_on_by_hand(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        foreach ([OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT] as $status) {
            $order = $this->advanceOrder($customer, ['status' => $status, 'accepted_at' => now()]);

            $this->actingAs($agent)->postJson("/api/agent/orders/{$order->id}/advance")
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'ADVANCE_AWAITING_PAYMENT');

            $this->assertSame($status, $order->fresh()->status);
        }
    }

    // -----------------------------------------------------------------
    // What the page reads
    // -----------------------------------------------------------------

    public function test_orders_in_the_kitchen_stay_on_the_list_until_collected(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $preparing = $this->advanceOrder($customer, ['status' => OrderStatus::PREPARING, 'payment_status' => 'paid']);
        $ready = $this->advanceOrder($customer, ['status' => OrderStatus::READY_FOR_PICKUP, 'payment_status' => 'paid']);

        $response = $this->actingAs($this->user(User::ROLE_ADMIN))
            ->getJson('/api/agent/advance-requests')
            ->assertOk()
            ->assertJsonPath('summary.in_kitchen', 2);

        $this->assertEqualsCanonicalizing(
            [$preparing->id, $ready->id],
            array_column($response->json('in_kitchen'), 'id')
        );
    }

    public function test_recently_closed_requests_are_listed_latest_first(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $rejected = $this->advanceOrder($customer, ['status' => OrderStatus::REJECTED, 'cancellation_reason' => 'Fully booked.']);
        $rejected->forceFill(['closed_at' => now()->subHours(3)])->save();
        $completed = $this->advanceOrder($customer, ['status' => OrderStatus::COMPLETED, 'payment_status' => 'paid']);
        $old = $this->advanceOrder($customer, ['status' => OrderStatus::CANCELLED]);
        $old->forceFill(['closed_at' => now()->subDays(8)])->save();
        $this->immediateOrder($customer)->forceFill(['status' => OrderStatus::COMPLETED])->save();

        $response = $this->actingAs($this->user(User::ROLE_ADMIN))
            ->getJson('/api/agent/advance-requests')
            ->assertOk()
            ->assertJsonPath('summary.closed_recently', 2);

        $this->assertSame([$completed->id, $rejected->id], array_column($response->json('closed'), 'id'));
        $this->assertSame('Fully booked.', $response->json('closed.1.cancellation_reason'));
    }

    public function test_the_list_says_when_preparation_may_start(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $today = $this->advanceOrder($customer, [
            'status' => OrderStatus::SCHEDULED,
            'payment_status' => 'paid',
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->setTime(12, 0),
        ]);
        $later = $this->advanceOrder($customer, ['status' => OrderStatus::SCHEDULED, 'payment_status' => 'paid']);

        $scheduled = collect($this->actingAs($this->user(User::ROLE_ADMIN))
            ->getJson('/api/agent/advance-requests')
            ->json('scheduled'))->keyBy('id');

        $this->assertTrue($scheduled[$today->id]['can_start_preparing']);
        $this->assertFalse($scheduled[$later->id]['can_start_preparing']);
        $this->assertSame([], $scheduled[$today->id]['items'][0]['addons']);
    }

    public function test_the_list_says_when_the_customer_was_last_reminded(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);
        $order = $this->advanceOrder($customer, ['status' => OrderStatus::AWAITING_PAYMENT, 'accepted_at' => now()]);

        $before = $this->actingAs($agent)->getJson('/api/agent/advance-requests')->json('awaiting_payment.0');
        $this->assertNull($before['last_reminded_at']);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/remind")->assertOk();

        $after = $this->actingAs($agent)->getJson('/api/agent/advance-requests')->json('awaiting_payment.0');
        $this->assertNotNull($after['last_reminded_at']);
        $this->assertTrue(
            CarbonImmutable::parse($after['can_remind_at'])->equalTo(
                CarbonImmutable::parse($after['last_reminded_at'])->addMinutes(60)
            )
        );
    }

    public function test_from_preparing_an_advance_order_runs_the_ordinary_pickup_chain(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::PREPARING,
            'payment_status' => 'paid',
        ]);

        $this->actingAs($agent)->postJson("/api/agent/orders/{$order->id}/advance")->assertOk();
        $this->assertSame(OrderStatus::READY_FOR_PICKUP, $order->fresh()->status);

        $this->actingAs($agent)->postJson("/api/agent/orders/{$order->id}/advance")->assertOk();
        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
    }

    public function test_a_submitted_request_is_not_advanced_from_the_queue_endpoint(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->actingAs($agent)->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ADVANCE_NEEDS_DECISION');
    }
}
