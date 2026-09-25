<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\CancellationPolicy;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cancelling an order, and whether the money comes back (BR-19, BR-20, BR-21).
 *
 * The rule under test is the one the business chose deliberately: cancelling is
 * always allowed until the order closes, and only the refund is conditional.
 * Blocking the button does not bring a customer who has changed their mind to
 * the counter - it only stops the kitchen finding out in time to stop cooking.
 */
class CancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00', 'Asia/Manila')->utc());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function policy(): CancellationPolicy
    {
        return app(CancellationPolicy::class);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER]);
    }

    /**
     * Paid by default.
     *
     * Most of these assertions are about the refund *window*, and an unpaid
     * order never reaches that question - it has nothing to refund whatever the
     * window says. The unpaid case has its own section below.
     */
    private function order(
        string $status = OrderStatus::PLACED,
        string $type = 'pickup',
        ?User $owner = null,
        ?string $scheduledFor = null,
        bool $paid = true,
    ): Order {
        $order = Order::create([
            'user_id' => ($owner ?? $this->customer())->id,
            'order_type' => $type,
            'status' => OrderStatus::PLACED,
            'total_price' => 440,
            'total_amount' => 440,
            'scheduled_for' => $scheduledFor,
            'payment_status' => $paid ? 'paid' : 'unpaid',
        ]);

        if ($status !== OrderStatus::PLACED) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->fresh();
    }

    /** An advance order: pickup, dated, and on the advance chain. */
    private function advanceOrder(
        string $status = OrderStatus::SCHEDULED,
        ?User $owner = null,
        string $collectIn = '5 days',
        bool $paid = true,
    ): Order {
        return $this->order(
            $status,
            owner: $owner,
            scheduledFor: CarbonImmutable::now()->add($collectIn)->toDateTimeString(),
            paid: $paid,
        );
    }

    // -----------------------------------------------------------------
    // Cancelling is always allowed until the order closes
    // -----------------------------------------------------------------

    public function test_every_open_stage_can_still_be_cancelled(): void
    {
        foreach ([OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP] as $status) {
            $this->assertTrue(
                $this->policy()->canCancel($this->order($status)),
                "an order at {$status} must still be cancellable - the kitchen needs to know"
            );
        }
    }

    public function test_every_open_advance_stage_can_still_be_cancelled(): void
    {
        foreach (OrderStatus::advanceChain() as $status) {
            if ($status === OrderStatus::COMPLETED) {
                continue;
            }

            $this->assertTrue(
                $this->policy()->canCancel($this->advanceOrder($status)),
                "an advance order at {$status} must still be cancellable (BR-19)"
            );
        }
    }

    public function test_a_closed_order_cannot_be_cancelled(): void
    {
        foreach ([OrderStatus::COMPLETED, OrderStatus::DELIVERED, OrderStatus::CANCELLED] as $status) {
            $decision = $this->policy()->for($this->order($status));

            $this->assertFalse($decision['can_cancel']);
            $this->assertSame('ORDER_ALREADY_CLOSED', $decision['code']);
        }
    }

    public function test_a_rejected_request_is_closed(): void
    {
        $decision = $this->policy()->for($this->advanceOrder(OrderStatus::REJECTED));

        $this->assertFalse($decision['can_cancel'], 'the shop already ended this one');
        $this->assertSame('ORDER_ALREADY_CLOSED', $decision['code']);
    }

    // -----------------------------------------------------------------
    // The refund window, by status
    // -----------------------------------------------------------------

    public function test_nothing_spent_yet_means_a_full_refund(): void
    {
        foreach ([OrderStatus::PLACED, OrderStatus::CONFIRMED] as $status) {
            $decision = $this->policy()->for($this->order($status));

            $this->assertTrue($decision['refundable'], "{$status} costs the shop nothing yet");
            $this->assertSame(440.0, $decision['refund_amount']);
        }
    }

    public function test_once_the_kitchen_has_started_there_is_no_refund(): void
    {
        foreach ([OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP] as $status) {
            $decision = $this->policy()->for($this->order($status));

            $this->assertTrue($decision['can_cancel'], 'still cancellable - that is the whole point');
            $this->assertFalse($decision['refundable']);
            $this->assertSame(0.0, $decision['refund_amount']);
            $this->assertSame('KITCHEN_STARTED', $decision['code']);
        }
    }

    public function test_the_window_is_the_same_on_the_delivery_branch(): void
    {
        $this->assertTrue($this->policy()->isRefundable($this->order(OrderStatus::CONFIRMED, 'delivery')));
        $this->assertFalse($this->policy()->isRefundable($this->order(OrderStatus::ON_THE_WAY, 'delivery')));
    }

    public function test_the_store_manager_can_move_the_window(): void
    {
        $preparing = $this->order(OrderStatus::PREPARING);

        $this->assertFalse($this->policy()->isRefundable($preparing));

        Setting::put(Setting::CANCELLATION_FULL_REFUND_THROUGH, OrderStatus::PREPARING);

        $this->assertTrue($this->policy()->isRefundable($preparing->fresh()));
    }

    public function test_a_tighter_window_can_be_set_too(): void
    {
        Setting::put(Setting::CANCELLATION_FULL_REFUND_THROUGH, OrderStatus::PLACED);

        $this->assertTrue($this->policy()->isRefundable($this->order(OrderStatus::PLACED)));
        $this->assertFalse($this->policy()->isRefundable($this->order(OrderStatus::CONFIRMED)));
    }

    public function test_a_nonsense_setting_falls_back_to_the_default(): void
    {
        Setting::put(Setting::CANCELLATION_FULL_REFUND_THROUGH, 'delivered');

        $this->assertSame(OrderStatus::CONFIRMED, $this->policy()->fullRefundThrough());
    }

    // -----------------------------------------------------------------
    // Nothing paid is not the same as no refund
    // -----------------------------------------------------------------
    //
    // An advance order is legitimately unpaid from `submitted` all the way to
    // `awaiting_payment`. Reporting "your payment will be refunded in full"
    // there would be a lie, and recording what we would owe would leave the
    // payment provider a refund to settle against a charge that never happened.

    public function test_an_unpaid_order_has_nothing_to_refund(): void
    {
        $decision = $this->policy()->for($this->order(OrderStatus::CONFIRMED, paid: false));

        $this->assertTrue($decision['can_cancel']);
        $this->assertFalse($decision['refundable']);
        $this->assertSame(0.0, $decision['refund_amount']);
        $this->assertSame('NOTHING_PAID', $decision['code']);
    }

    public function test_nothing_paid_is_reported_ahead_of_the_kitchen_having_started(): void
    {
        // Both are true, but only one of them is the reason the customer gets
        // no money back, and it is the one that is not their problem.
        $decision = $this->policy()->for($this->order(OrderStatus::PREPARING, paid: false));

        $this->assertSame('NOTHING_PAID', $decision['code']);
    }

    public function test_the_unpaid_advance_stages_have_nothing_to_refund(): void
    {
        foreach ([OrderStatus::SUBMITTED, OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT] as $status) {
            $decision = $this->policy()->for($this->advanceOrder($status, paid: false));

            $this->assertTrue($decision['can_cancel'], "a request at {$status} can always be withdrawn");
            $this->assertSame('NOTHING_PAID', $decision['code']);
            $this->assertSame(0.0, $decision['refund_amount']);
        }
    }

    // -----------------------------------------------------------------
    // Advance orders follow the same rule (BR-21)
    // -----------------------------------------------------------------

    public function test_an_advance_order_resting_in_scheduled_is_fully_refundable(): void
    {
        $decision = $this->policy()->for($this->advanceOrder(OrderStatus::SCHEDULED));

        $this->assertTrue($decision['refundable']);
        $this->assertSame(440.0, $decision['refund_amount']);
        $this->assertNull($decision['code']);
    }

    /**
     * The whole point of dropping the old time-based cutoff.
     *
     * A booking a month out and one collected in an hour are refundable on
     * identical terms, because in both cases the kitchen has not started. This
     * is the assertion that would fail if a schedule cutoff came back.
     */
    public function test_how_close_the_collection_date_is_makes_no_difference(): void
    {
        foreach (['1 hour', '6 hours', '5 days', '30 days'] as $away) {
            $this->assertTrue(
                $this->policy()->isRefundable($this->advanceOrder(OrderStatus::SCHEDULED, collectIn: $away)),
                "collecting in {$away} must not change the refund - only the kitchen starting does"
            );
        }
    }

    public function test_an_advance_order_stops_being_refundable_once_the_kitchen_starts(): void
    {
        $decision = $this->policy()->for($this->advanceOrder(OrderStatus::PREPARING));

        $this->assertTrue($decision['can_cancel']);
        $this->assertFalse($decision['refundable']);
        $this->assertSame('KITCHEN_STARTED', $decision['code']);
    }

    public function test_a_paid_advance_order_is_refundable_right_up_to_preparation(): void
    {
        foreach ([OrderStatus::CONFIRMED, OrderStatus::SCHEDULED] as $status) {
            $this->assertTrue(
                $this->policy()->isRefundable($this->advanceOrder($status)),
                "{$status} sits before the kitchen starting"
            );
        }
    }

    public function test_the_last_refundable_stage_is_named_per_order_kind(): void
    {
        // Same setting, two chains: the customer is told the stage that applies
        // to the order they are actually looking at.
        $this->assertSame(
            OrderStatus::CONFIRMED,
            $this->policy()->for($this->order(OrderStatus::PLACED))['refundable_through']
        );

        $this->assertSame(
            OrderStatus::SCHEDULED,
            $this->policy()->for($this->advanceOrder(OrderStatus::CONFIRMED))['refundable_through']
        );
    }

    public function test_moving_the_threshold_moves_the_advance_window_too(): void
    {
        $preparing = $this->advanceOrder(OrderStatus::PREPARING);

        $this->assertFalse($this->policy()->isRefundable($preparing));

        // One setting governs both chains - there is no second advance-only
        // knob to fall out of step with it.
        Setting::put(Setting::CANCELLATION_FULL_REFUND_THROUGH, OrderStatus::PREPARING);

        $this->assertTrue($this->policy()->isRefundable($preparing->fresh()));
        $this->assertSame(
            OrderStatus::PREPARING,
            $this->policy()->for($preparing->fresh())['refundable_through']
        );
    }

    // -----------------------------------------------------------------
    // Through the endpoint
    // -----------------------------------------------------------------

    public function test_a_customer_can_now_cancel_after_confirmation(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::CONFIRMED, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', true)
            ->assertJsonPath('order.status', OrderStatus::CANCELLED);

        $this->assertSame('440.00', $order->fresh()->refund_owed);
    }

    public function test_cancelling_once_cooking_has_started_records_no_refund(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::PREPARING, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', false)
            ->assertJsonPath('refund_amount', 0)
            ->assertJsonPath('refund_code', 'KITCHEN_STARTED');

        $this->assertSame('0.00', $order->fresh()->refund_owed);
    }

    public function test_a_customer_cancels_a_scheduled_advance_order_and_is_refunded(): void
    {
        $customer = $this->customer();
        $order = $this->advanceOrder(OrderStatus::SCHEDULED, owner: $customer, collectIn: '20 days');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', true)
            ->assertJsonPath('refund_amount', 440)
            ->assertJsonPath('order.status', OrderStatus::CANCELLED);

        $this->assertSame('440.00', $order->fresh()->refund_owed);
    }

    public function test_a_customer_withdraws_a_request_the_store_has_not_answered(): void
    {
        $customer = $this->customer();
        $order = $this->advanceOrder(OrderStatus::SUBMITTED, owner: $customer, paid: false);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', false)
            ->assertJsonPath('refund_code', 'NOTHING_PAID')
            ->assertJsonPath('order.status', OrderStatus::CANCELLED);

        $this->assertSame('0.00', $order->fresh()->refund_owed);
        $this->assertSame($customer->id, $order->fresh()->cancelled_by);
    }

    public function test_a_customer_cancels_an_accepted_request_before_paying_for_it(): void
    {
        $customer = $this->customer();
        $order = $this->advanceOrder(OrderStatus::AWAITING_PAYMENT, owner: $customer, paid: false);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', false)
            ->assertJsonPath('refund_code', 'NOTHING_PAID');

        $this->assertSame('0.00', $order->fresh()->refund_owed);
    }

    public function test_a_rejected_request_cannot_then_be_cancelled(): void
    {
        $customer = $this->customer();
        $order = $this->advanceOrder(OrderStatus::REJECTED, owner: $customer, paid: false);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'ORDER_ALREADY_CLOSED');
    }

    public function test_the_customer_is_recorded_as_having_cancelled_it(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::CONFIRMED, owner: $customer);

        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();

        $this->assertSame($customer->id, $order->fresh()->cancelled_by);
    }

    public function test_a_closed_order_is_refused_at_the_endpoint(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::COMPLETED, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'ORDER_ALREADY_CLOSED');
    }

    public function test_one_customer_cannot_cancel_another_customers_order(): void
    {
        $order = $this->advanceOrder(OrderStatus::SCHEDULED);

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(OrderStatus::SCHEDULED, $order->fresh()->status);
    }

    public function test_the_tracker_reports_both_answers_separately(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::PREPARING, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.can_cancel', true)
            ->assertJsonPath('order.cancellation.refundable', false)
            ->assertJsonPath('order.cancellation.code', 'KITCHEN_STARTED');
    }

    public function test_the_tracker_walks_an_advance_order_along_its_own_chain(): void
    {
        $customer = $this->customer();
        $order = $this->advanceOrder(OrderStatus::SCHEDULED, owner: $customer);

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.is_advance', true)
            ->assertJsonPath('order.step_count', count(OrderStatus::advanceChain()))
            ->assertJsonPath('order.cancellation.refundable', true)
            ->assertJsonPath('order.cancellation.refundable_through', OrderStatus::SCHEDULED);

        // Before this, an advance status was not in the chain the tracker read,
        // so the progress bar reported every step as "upcoming" while the order
        // was demonstrably underway.
        $this->assertSame(
            OrderStatus::SCHEDULED,
            collect($response->json('order.steps'))->firstWhere('state', 'current')['key'] ?? null
        );
    }

    // -----------------------------------------------------------------
    // The agent's side
    // -----------------------------------------------------------------

    public function test_a_store_rejection_refunds_regardless_of_the_window(): void
    {
        $order = $this->order(OrderStatus::PREPARING);
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // The shop ran out of chicken. That is not the customer's fault, so the
        // kitchen having started does not cost them their money.
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'We ran out of chicken.'])
            ->assertOk()
            ->assertJsonPath('refund_owed', 440);

        $this->assertSame($agent->id, $order->fresh()->cancelled_by);
    }

    public function test_an_agent_can_waive_the_refund_when_the_customer_phoned_in(): void
    {
        $order = $this->order(OrderStatus::PREPARING);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", [
                'reason' => 'Customer called to cancel.',
                'refund' => false,
            ])
            ->assertOk()
            ->assertJsonPath('refund_owed', 0);
    }

    public function test_cancelling_an_unpaid_advance_order_records_no_refund_to_pay_out(): void
    {
        $order = $this->advanceOrder(OrderStatus::AWAITING_PAYMENT, paid: false);

        // The agent asked for a refund in good faith, but there is no charge to
        // reverse. Recording 440 here would be a payout the provider cannot
        // settle once PayMongo is wired up.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", [
                'reason' => 'Kitchen cannot make this after all.',
                'refund' => true,
            ])
            ->assertOk()
            ->assertJsonPath('refund_owed', 0);

        $this->assertSame('0.00', $order->fresh()->refund_owed);
    }

    public function test_an_agent_can_cancel_a_scheduled_advance_order_with_a_refund(): void
    {
        $order = $this->advanceOrder(OrderStatus::SCHEDULED, collectIn: '10 days');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Closed for a family event.'])
            ->assertOk()
            ->assertJsonPath('refund_owed', 440);

        $this->assertSame('Closed for a family event.', $order->fresh()->cancellation_reason);
    }

    // -----------------------------------------------------------------
    // The Store Manager's settings
    // -----------------------------------------------------------------

    private const PASSWORD = 'Password123!';

    private function manager(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    public function test_the_store_manager_reads_the_rules(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/admin/settings/cancellation')
            ->assertOk()
            ->assertJsonPath('full_refund_through', OrderStatus::CONFIRMED)
            // The advance cutoff was removed with BR-21: one status-based rule
            // governs both kinds, so there is no second knob to read.
            ->assertJsonMissingPath('advance_cutoff_hours');
    }

    public function test_the_store_manager_can_change_the_rules(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/cancellation', [
                'full_refund_through' => OrderStatus::PREPARING,
                'password' => self::PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('full_refund_through', OrderStatus::PREPARING);

        $this->assertTrue($this->policy()->isRefundable($this->order(OrderStatus::PREPARING)));
    }

    public function test_a_store_agent_cannot_change_the_rules(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->getJson('/api/admin/settings/cancellation')
            ->assertForbidden();
    }

    public function test_a_refund_window_cannot_extend_past_the_kitchen_starting(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/cancellation', [
                'full_refund_through' => OrderStatus::DELIVERED,
                'password' => self::PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('full_refund_through');
    }
}
