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

    private function order(
        string $status = OrderStatus::PLACED,
        string $type = 'pickup',
        ?User $owner = null,
        ?string $scheduledFor = null,
    ): Order {
        $order = Order::create([
            'user_id' => ($owner ?? $this->customer())->id,
            'order_type' => $type,
            'status' => OrderStatus::PLACED,
            'total_price' => 440,
            'total_amount' => 440,
            'scheduled_for' => $scheduledFor,
        ]);

        if ($status !== OrderStatus::PLACED) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->fresh();
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

    public function test_a_closed_order_cannot_be_cancelled(): void
    {
        foreach ([OrderStatus::COMPLETED, OrderStatus::DELIVERED, OrderStatus::CANCELLED] as $status) {
            $decision = $this->policy()->for($this->order($status));

            $this->assertFalse($decision['can_cancel']);
            $this->assertSame('ORDER_ALREADY_CLOSED', $decision['code']);
        }
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
    // The refund window, by schedule (BR-21)
    // -----------------------------------------------------------------

    public function test_an_advance_order_far_from_its_date_is_refundable(): void
    {
        $order = $this->order(
            OrderStatus::CONFIRMED,
            scheduledFor: CarbonImmutable::now()->addDays(5)->toDateTimeString()
        );

        $this->assertTrue($this->policy()->isRefundable($order));
    }

    public function test_an_advance_order_inside_the_cutoff_is_not_refundable(): void
    {
        $order = $this->order(
            OrderStatus::CONFIRMED,
            scheduledFor: CarbonImmutable::now()->addHours(6)->toDateTimeString()
        );

        $decision = $this->policy()->for($order);

        $this->assertTrue($decision['can_cancel'], 'they can still tell us they are not coming');
        $this->assertFalse($decision['refundable']);
        $this->assertSame('PAST_SCHEDULE_CUTOFF', $decision['code']);
    }

    public function test_the_cutoff_is_configurable(): void
    {
        $order = $this->order(
            OrderStatus::CONFIRMED,
            scheduledFor: CarbonImmutable::now()->addDays(2)->toDateTimeString()
        );

        $this->assertTrue($this->policy()->isRefundable($order));

        // A shop buying stock a week ahead needs a longer runway.
        Setting::put(Setting::CANCELLATION_ADVANCE_CUTOFF_HOURS, 168);

        $this->assertFalse($this->policy()->isRefundable($order->fresh()));
    }

    public function test_the_stricter_of_the_two_windows_wins(): void
    {
        // Far from its date, but the kitchen has started anyway.
        $order = $this->order(
            OrderStatus::PREPARING,
            scheduledFor: CarbonImmutable::now()->addDays(5)->toDateTimeString()
        );

        $this->assertFalse($this->policy()->isRefundable($order), 'the food is already being cooked');
    }

    public function test_a_same_day_order_is_unaffected_by_the_schedule_rule(): void
    {
        $decision = $this->policy()->for($this->order(OrderStatus::CONFIRMED));

        $this->assertTrue($decision['refundable']);
        $this->assertNull($decision['free_until'], 'nothing to count down to');
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
            ->assertJsonPath('refund_amount', 0);

        $this->assertSame('0.00', $order->fresh()->refund_owed);
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
            ->assertJsonPath('advance_cutoff_hours', 24);
    }

    public function test_the_store_manager_can_change_the_rules(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/cancellation', [
                'full_refund_through' => OrderStatus::PREPARING,
                'advance_cutoff_hours' => 48,
                'password' => self::PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('full_refund_through', OrderStatus::PREPARING)
            ->assertJsonPath('advance_cutoff_hours', 48);

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
                'advance_cutoff_hours' => 24,
                'password' => self::PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('full_refund_through');
    }
}
