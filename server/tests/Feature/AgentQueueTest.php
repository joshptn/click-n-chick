<?php

namespace Tests\Feature;

use App\Events\OrderBroadcast;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The Store Agent's fulfilment surface.
 *
 * UC-QUE-005 (process the next order), UC-QUE-006 (monitor the active queue),
 * UC-OPS-001 (incoming orders), UC-OPS-003 (reject), UC-OPS-004/005 (advance),
 * and UC-OPS-012 (at-a-glance status).
 */
class AgentQueueTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = User::ROLE_ADMIN): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function food(): Food
    {
        return Food::create([
            'thumbnail' => 'https://example.test/chicken.png',
            'food_name' => 'Fried Chicken',
            'price' => 120,
            'description' => 'One piece with rice.',
            'stock_quantity' => 50,
            'is_available' => true,
        ]);
    }

    /** An order already in the queue, which is the only kind the agent ever sees. */
    private function queued(string $type = 'pickup', string $status = OrderStatus::PLACED, ?CarbonImmutable $at = null): Order
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'order_type' => $type,
            'status' => OrderStatus::PLACED,
            'total_price' => 120,
            'total_amount' => 120,
        ]);

        $order->enterQueue($at);

        if ($status !== OrderStatus::PLACED) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->fresh();
    }

    // -----------------------------------------------------------------
    // Reading the queue (UC-QUE-006, UC-OPS-001)
    // -----------------------------------------------------------------

    public function test_the_queue_is_returned_in_entry_order_with_positions(): void
    {
        $first = $this->queued();
        $second = $this->queued('delivery', OrderStatus::CONFIRMED);
        $third = $this->queued('pickup', OrderStatus::PREPARING);

        $response = $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk();

        $response->assertJsonPath('line.0.id', $first->id)
            ->assertJsonPath('line.0.queue_position', 1)
            ->assertJsonPath('line.1.id', $second->id)
            ->assertJsonPath('line.1.queue_position', 2)
            ->assertJsonPath('line.2.id', $third->id)
            ->assertJsonPath('line.2.queue_position', 3);
    }

    public function test_an_order_the_kitchen_has_finished_with_moves_out_of_the_line(): void
    {
        $this->queued('pickup', OrderStatus::READY_FOR_PICKUP);
        $this->queued('delivery', OrderStatus::ON_THE_WAY);
        $cooking = $this->queued('pickup', OrderStatus::PREPARING);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonCount(1, 'line')
            ->assertJsonCount(2, 'handover')
            ->assertJsonPath('line.0.id', $cooking->id)
            ->assertJsonPath('line.0.queue_position', 1);
    }

    public function test_finished_orders_are_out_of_both_lists(): void
    {
        $this->queued('pickup', OrderStatus::COMPLETED);
        $this->queued('pickup', OrderStatus::CANCELLED);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonCount(0, 'line')
            ->assertJsonCount(0, 'handover')
            ->assertJsonPath('summary.completed_today', 1)
            ->assertJsonPath('summary.cancelled_today', 1);
    }

    public function test_the_summary_reports_the_shape_of_the_day(): void
    {
        $this->queued('pickup', OrderStatus::PLACED);
        $this->queued('pickup', OrderStatus::PREPARING);
        $this->queued('pickup', OrderStatus::READY_FOR_PICKUP);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonPath('summary.in_line', 2)
            ->assertJsonPath('summary.awaiting_handover', 1)
            ->assertJsonPath('summary.awaiting_confirmation', 1);
    }

    public function test_yesterdays_loose_ends_stay_out_of_todays_queue(): void
    {
        $this->queued('pickup', OrderStatus::PREPARING, CarbonImmutable::parse('2020-01-01 03:00:00', 'UTC'));
        $today = $this->queued();

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonCount(1, 'line')
            ->assertJsonPath('line.0.id', $today->id);
    }

    public function test_a_past_day_can_be_read_back(): void
    {
        $old = $this->queued('pickup', OrderStatus::PREPARING, CarbonImmutable::parse('2026-01-15 03:00:00', 'UTC'));

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue?date=2026-01-15')
            ->assertOk()
            ->assertJsonPath('date', '2026-01-15')
            ->assertJsonPath('line.0.id', $old->id);
    }

    public function test_the_queue_carries_what_the_agent_has_to_cook_and_who_for(): void
    {
        $order = $this->queued();
        OrderItem::create([
            'order_id' => $order->id,
            'food_id' => $this->food()->id,
            'quantity' => 2,
            'price' => 120,
        ]);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonPath('line.0.items.0.food_name', 'Fried Chicken')
            ->assertJsonPath('line.0.items.0.quantity', 2)
            ->assertJsonPath('line.0.queue_label', 'Online 1')
            ->assertJsonPath('line.0.status_label', 'Placed')
            ->assertJsonPath('line.0.next_status', OrderStatus::CONFIRMED);
    }

    public function test_the_queue_carries_the_store_state_for_the_dashboard(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonStructure(['store' => ['accepting_orders', 'accepting_delivery', 'opens_at', 'closes_at']]);
    }

    // -----------------------------------------------------------------
    // The next order (UC-QUE-005)
    // -----------------------------------------------------------------

    public function test_next_returns_the_head_of_the_line(): void
    {
        $first = $this->queued();
        $this->queued();

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue/next')
            ->assertOk()
            ->assertJsonPath('order.id', $first->id)
            ->assertJsonPath('order.queue_position', 1);
    }

    public function test_next_skips_orders_the_kitchen_is_done_with(): void
    {
        $this->queued('pickup', OrderStatus::READY_FOR_PICKUP);
        $cooking = $this->queued();

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue/next')
            ->assertOk()
            ->assertJsonPath('order.id', $cooking->id);
    }

    public function test_an_empty_queue_is_a_quiet_afternoon_not_an_error(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/queue/next')
            ->assertOk()
            ->assertJsonPath('order', null);
    }

    // -----------------------------------------------------------------
    // Advancing (UC-OPS-002/004/005)
    // -----------------------------------------------------------------

    public function test_advance_walks_the_pickup_chain_without_naming_a_target(): void
    {
        $order = $this->queued('pickup');
        $agent = $this->staff();

        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP, OrderStatus::COMPLETED] as $expected) {
            $this->actingAs($agent, 'sanctum')
                ->postJson("/api/agent/orders/{$order->id}/advance")
                ->assertOk()
                ->assertJsonPath('order.status', $expected);
        }
    }

    public function test_advance_walks_the_delivery_chain(): void
    {
        $order = $this->queued('delivery');
        $agent = $this->staff();

        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::ON_THE_WAY, OrderStatus::DELIVERED] as $expected) {
            $this->actingAs($agent, 'sanctum')
                ->postJson("/api/agent/orders/{$order->id}/advance")
                ->assertOk()
                ->assertJsonPath('order.status', $expected);
        }
    }

    public function test_a_finished_order_cannot_be_advanced(): void
    {
        $order = $this->queued('pickup', OrderStatus::COMPLETED);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOTHING_TO_ADVANCE');
    }

    public function test_marking_a_delivery_received_is_the_store_agents_override_alone(): void
    {
        $order = $this->queued('delivery', OrderStatus::ON_THE_WAY);

        // BR-30 / FR-02.10: the Store Manager does not stand at the door.
        $this->actingAs($this->staff(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertForbidden();

        $this->assertSame(OrderStatus::ON_THE_WAY, $order->fresh()->status);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertOk();

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_the_status_endpoint_enforces_the_same_delivery_override_rule(): void
    {
        $order = $this->queued('delivery', OrderStatus::ON_THE_WAY);

        $this->actingAs($this->staff(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->putJson("/api/order/{$order->id}/status", ['status' => OrderStatus::DELIVERED])
            ->assertForbidden();
    }

    public function test_advancing_broadcasts(): void
    {
        Event::fake([OrderBroadcast::class]);

        $order = $this->queued();

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertOk();

        Event::assertDispatched(OrderBroadcast::class, fn (OrderBroadcast $e) => $e->order->id === $order->id);
    }

    public function test_advancing_closes_the_line_up_behind_it(): void
    {
        $head = $this->queued('pickup', OrderStatus::PREPARING);
        $behind = $this->queued();
        $agent = $this->staff();

        $this->actingAs($agent, 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertJsonPath('line.1.queue_position', 2);

        // Onto the shelf: out of the kitchen, out of the line.
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/agent/orders/{$head->id}/advance")
            ->assertOk();

        $this->actingAs($agent, 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertJsonPath('line.0.id', $behind->id)
            ->assertJsonPath('line.0.queue_position', 1);
    }

    // -----------------------------------------------------------------
    // Rejecting (UC-OPS-003, BR-20)
    // -----------------------------------------------------------------

    public function test_an_agent_can_reject_an_order_with_a_reason(): void
    {
        $order = $this->queued('pickup', OrderStatus::CONFIRMED);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'We ran out of chicken.'])
            ->assertOk()
            ->assertJsonPath('order.status', OrderStatus::CANCELLED)
            ->assertJsonPath('order.cancellation_reason', 'We ran out of chicken.');
    }

    public function test_the_reason_reaches_the_customers_notification(): void
    {
        $order = $this->queued();

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Kitchen closed early.'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $order->user_id,
            'body' => "Your order #{$order->id} has been cancelled: Kitchen closed early.",
        ]);
    }

    public function test_a_reason_is_optional(): void
    {
        $order = $this->queued();

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('order.cancellation_reason', null);
    }

    public function test_a_finished_order_cannot_be_cancelled(): void
    {
        $order = $this->queued('pickup', OrderStatus::COMPLETED);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ORDER_ALREADY_CLOSED');

        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
    }

    public function test_an_over_long_reason_is_refused(): void
    {
        $order = $this->queued();

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    // -----------------------------------------------------------------
    // Who may reach any of this
    // -----------------------------------------------------------------

    public function test_a_customer_cannot_reach_the_queue(): void
    {
        $order = $this->queued();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer, 'sanctum')->getJson('/api/agent/queue')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->getJson('/api/agent/queue/next')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson("/api/agent/orders/{$order->id}/advance")->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson("/api/agent/orders/{$order->id}/cancel")->assertForbidden();
    }

    public function test_a_guest_cannot_reach_the_queue(): void
    {
        $this->getJson('/api/agent/queue')->assertUnauthorized();
    }

    public function test_the_store_manager_may_watch_the_queue(): void
    {
        $this->queued();

        $this->actingAs($this->staff(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonCount(1, 'line');
    }

    // -----------------------------------------------------------------
    // UC-OPS-007 browsing
    // -----------------------------------------------------------------

    public function test_the_records_list_will_not_be_asked_for_every_order_ever_taken(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/orders/all?per_page=1000000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_the_records_list_rejects_a_status_outside_the_lifecycle(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/orders/all?status=approved')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_records_list_still_accepts_all(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/orders/all?status=all&per_page=25')
            ->assertOk();
    }
}
