<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\CancellationPolicy;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cancelling an order, and whether the money comes back (BR-19 to BR-21a,
 * decided 2026-11-17).
 *
 *   immediate  the customer may cancel only while `placed`, and is refunded
 *              in full; once a Store Agent confirms it, no longer
 *   advance    the customer may cancel only while unpaid, with nothing to
 *              refund; once paid, no longer
 *   the shop   may cancel any open order, with a reason, and for a paid one
 *              chooses refund in full or no refund
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

    private function agent(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** An immediate order, paid by default: it is paid before it is placed. */
    private function order(
        string $status = OrderStatus::PLACED,
        string $type = 'pickup',
        ?User $owner = null,
        ?string $scheduledFor = null,
        bool $paid = true,
    ): Order {
        $order = $this->orderWith([
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

    private function advanceOrder(string $status = OrderStatus::SCHEDULED, ?User $owner = null, bool $paid = true): Order
    {
        return $this->order(
            $status,
            owner: $owner,
            scheduledFor: CarbonImmutable::now()->addDays(5)->toDateTimeString(),
            paid: $paid,
        );
    }

    // -----------------------------------------------------------------
    // Immediate orders: only while placed
    // -----------------------------------------------------------------

    public function test_a_placed_order_can_be_cancelled_for_a_full_refund(): void
    {
        foreach (['pickup', 'delivery'] as $type) {
            $decision = $this->policy()->for($this->order(OrderStatus::PLACED, $type));

            $this->assertTrue($decision['can_cancel'], $type);
            $this->assertTrue($decision['refundable'], $type);
            $this->assertSame(440.0, $decision['refund_amount'], $type);
            $this->assertNull($decision['code'], $type);
        }
    }

    public function test_once_the_store_confirms_it_the_customer_cannot_cancel(): void
    {
        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP] as $status) {
            $decision = $this->policy()->for($this->order($status));

            $this->assertFalse($decision['can_cancel'], $status);
            $this->assertSame(CancellationPolicy::STORE_CONFIRMED, $decision['code'], $status);
        }

        $this->assertFalse($this->policy()->canCancel($this->order(OrderStatus::ON_THE_WAY, 'delivery')));
    }

    public function test_an_order_still_being_paid_for_cannot_be_cancelled(): void
    {
        $decision = $this->policy()->for($this->order(OrderStatus::AWAITING_PAYMENT, paid: false));

        $this->assertFalse($decision['can_cancel']);
        $this->assertSame(CancellationPolicy::AWAITING_PAYMENT, $decision['code']);
    }

    public function test_a_closed_order_cannot_be_cancelled(): void
    {
        foreach ([OrderStatus::COMPLETED, OrderStatus::CANCELLED] as $status) {
            $decision = $this->policy()->for($this->order($status));

            $this->assertFalse($decision['can_cancel']);
            $this->assertSame(CancellationPolicy::ALREADY_CLOSED, $decision['code']);
        }
    }

    // -----------------------------------------------------------------
    // Advance orders: only while unpaid
    // -----------------------------------------------------------------

    public function test_an_unpaid_advance_order_can_be_cancelled_with_nothing_to_refund(): void
    {
        foreach ([OrderStatus::SUBMITTED, OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT] as $status) {
            $decision = $this->policy()->for($this->advanceOrder($status, paid: false));

            $this->assertTrue($decision['can_cancel'], $status);
            $this->assertFalse($decision['refundable'], $status);
            $this->assertSame(0.0, $decision['refund_amount'], $status);
            $this->assertSame(CancellationPolicy::NOTHING_PAID, $decision['code'], $status);
        }
    }

    public function test_a_paid_advance_order_cannot_be_cancelled_by_the_customer(): void
    {
        foreach ([OrderStatus::CONFIRMED, OrderStatus::SCHEDULED, OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP] as $status) {
            $decision = $this->policy()->for($this->advanceOrder($status));

            $this->assertFalse($decision['can_cancel'], $status);
            $this->assertSame(CancellationPolicy::ADVANCE_PAID, $decision['code'], $status);
        }
    }

    public function test_a_rejected_request_is_closed(): void
    {
        $decision = $this->policy()->for($this->advanceOrder(OrderStatus::REJECTED, paid: false));

        $this->assertFalse($decision['can_cancel']);
        $this->assertSame(CancellationPolicy::ALREADY_CLOSED, $decision['code']);
    }

    // -----------------------------------------------------------------
    // The customer's endpoint
    // -----------------------------------------------------------------

    public function test_a_customer_cancels_a_placed_order_and_is_refunded(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::PLACED, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', true)
            ->assertJsonPath('refund_amount', 440)
            ->assertJsonPath('order.status', OrderStatus::CANCELLED);

        $this->assertSame('440.00', $order->fresh()->paidPayment->refund_amount);
        $this->assertSame($customer->id, $order->fresh()->cancelled_by);
    }

    /** Decided 2026-11-23: the store has 15 minutes from payment to confirm first. */
    public function test_a_customer_cancels_only_once_the_store_has_had_15_minutes(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::PLACED, owner: $customer);
        $order->paidPayment->forceFill(['paid_at' => now()->subMinutes(10)])->save();

        $decision = $this->policy()->for($order->fresh());

        $this->assertFalse($decision['can_cancel']);
        $this->assertSame(CancellationPolicy::AWAITING_STORE, $decision['code']);
        $this->assertSame(now()->addMinutes(5)->toIso8601String(), $decision['cancel_from']);
        $this->assertSame(
            "The store has until 10:05 AM to confirm your order. If it hasn't by then, you can cancel it here for a full refund.",
            $decision['message']
        );

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', CancellationPolicy::AWAITING_STORE);

        $this->assertSame(OrderStatus::PLACED, $order->fresh()->status);

        $this->travel(5)->minutes();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', true);
    }

    /** Wording decided 2026-11-23; the number is the first active Store Agent's, verified or not. */
    public function test_a_confirmed_order_tells_the_customer_to_contact_the_store(): void
    {
        $order = $this->order(OrderStatus::CONFIRMED);

        $this->assertSame(
            'Your order is already confirmed and will be prepared any time now, so it can no longer be cancelled through the app. Please contact the store if you need assistance.',
            $this->policy()->for($order)['message']
        );

        User::factory()->create(['role' => User::ROLE_ADMIN, 'account_status' => User::STATUS_SUSPENDED, 'phone_number' => '09170000009']);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'account_status' => User::STATUS_ACTIVE, 'phone_number' => '09170000001', 'phone_verified_at' => null]);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'account_status' => User::STATUS_ACTIVE, 'phone_number' => '09170000002']);
        $this->app->forgetScopedInstances();

        $this->assertStringEndsWith(
            'Please contact the store at 09170000001 if you need assistance.',
            $this->policy()->for($order)['message']
        );
    }

    public function test_a_confirmed_order_is_refused_at_the_endpoint(): void
    {
        $customer = $this->customer();
        $order = $this->order(OrderStatus::CONFIRMED, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', CancellationPolicy::STORE_CONFIRMED);

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
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

        $this->assertFalse($order->payments()->refunds()->exists());
    }

    public function test_a_customer_cancels_an_accepted_request_before_paying_for_it(): void
    {
        $customer = $this->customer();
        $order = $this->advanceOrder(OrderStatus::AWAITING_PAYMENT, owner: $customer, paid: false);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', false);

        $this->assertFalse($order->payments()->refunds()->exists());
    }

    public function test_a_paid_advance_order_is_refused_at_the_endpoint(): void
    {
        $customer = $this->customer();
        $order = $this->advanceOrder(OrderStatus::SCHEDULED, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', CancellationPolicy::ADVANCE_PAID);
    }

    public function test_one_customer_cannot_cancel_another_customers_order(): void
    {
        $order = $this->order(OrderStatus::PLACED);

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(OrderStatus::PLACED, $order->fresh()->status);
    }

    public function test_the_tracker_says_whether_it_can_be_cancelled_and_why(): void
    {
        $customer = $this->customer();
        $placed = $this->order(OrderStatus::PLACED, owner: $customer);
        $confirmed = $this->order(OrderStatus::CONFIRMED, owner: $customer);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$placed->id}")
            ->assertJsonPath('order.can_cancel', true)
            ->assertJsonPath('order.cancellation.refundable', true);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$confirmed->id}")
            ->assertJsonPath('order.can_cancel', false)
            ->assertJsonPath('order.cancellation.code', CancellationPolicy::STORE_CONFIRMED);
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
            ->assertJsonPath('order.can_cancel', false);

        $this->assertSame(
            OrderStatus::SCHEDULED,
            collect($response->json('order.steps'))->firstWhere('state', 'current')['key'] ?? null
        );
    }

    // -----------------------------------------------------------------
    // The shop's side (BR-21a)
    // -----------------------------------------------------------------

    public function test_the_shop_refunds_a_paid_order_in_full_when_it_chooses_to(): void
    {
        $order = $this->order(OrderStatus::PREPARING);
        $agent = $this->agent();

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'We ran out of chicken.', 'refund' => true])
            ->assertOk()
            ->assertJsonPath('refund.amount', 440)
            ->assertJsonPath('refund.state', 'refunding');

        $this->assertSame($agent->id, $order->fresh()->cancelled_by);
        $this->assertSame('We ran out of chicken.', $order->fresh()->cancellation_reason);
    }

    public function test_the_shop_can_cancel_a_paid_order_without_a_refund(): void
    {
        $order = $this->advanceOrder(OrderStatus::SCHEDULED);

        $this->actingAs($this->agent(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Never collected.', 'refund' => false])
            ->assertOk()
            ->assertJsonPath('refund.amount', 0)
            ->assertJsonPath('refund.state', 'none');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame('0.00', $order->fresh()->paidPayment->refund_amount);
    }

    public function test_a_paid_order_needs_an_explicit_refund_choice(): void
    {
        $order = $this->order(OrderStatus::CONFIRMED);

        $this->actingAs($this->agent(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Kitchen fire.'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REFUND_CHOICE_REQUIRED');

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_the_shop_must_give_a_reason(): void
    {
        $order = $this->order(OrderStatus::CONFIRMED);

        $this->actingAs($this->agent(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['refund' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->agent(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => '   ', 'refund' => true])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_an_unpaid_order_has_nothing_to_refund_whatever_is_chosen(): void
    {
        $order = $this->advanceOrder(OrderStatus::AWAITING_PAYMENT, paid: false);

        // No refund choice needed, and a refund asked for anyway records nothing.
        $this->actingAs($this->agent(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Kitchen cannot make this after all.', 'refund' => true])
            ->assertOk()
            ->assertJsonPath('refund', null);

        $this->assertFalse($order->payments()->refunds()->exists());
    }

    public function test_the_generic_status_route_cannot_cancel(): void
    {
        $order = $this->order(OrderStatus::CONFIRMED);

        $this->actingAs($this->agent(), 'sanctum')
            ->putJson("/api/order/{$order->id}/status", ['status' => OrderStatus::CANCELLED])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'USE_CANCEL_ACTION');

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // No refund threshold to configure any more
    // -----------------------------------------------------------------

    public function test_the_refund_threshold_setting_is_gone(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'password' => Hash::make('Password123!')]);

        $this->actingAs($manager, 'sanctum')->getJson('/api/admin/settings/cancellation')->assertNotFound();
    }
}
