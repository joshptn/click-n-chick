<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The canonical order lifecycle (BRD 3.1) and queue entry (BR-22, FR-08.1-3).
 *
 * Two things are being pinned here. First, that the branch is real: a pickup
 * order and a delivery order genuinely walk different chains, and neither can
 * be pushed onto the other's terminal state. Second, that a queue number is a
 * scarce resource - assigned once, never twice, and never duplicated even when
 * two payments land on an empty day at the same instant.
 */
class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function order(string $type = 'pickup', string $status = OrderStatus::PLACED): Order
    {
        return Order::create([
            'user_id' => User::factory()->create()->id,
            'order_type' => $type,
            'status' => $status,
            'total_price' => 100,
        ]);
    }

    private function advance(Order $order, string $status)
    {
        return $this->actingAs($this->agent(), 'sanctum')
            ->putJson("/api/order/{$order->id}/status", ['status' => $status]);
    }

    // -----------------------------------------------------------------
    // The chains (BRD 3.1, FR-02.7)
    // -----------------------------------------------------------------

    public function test_a_new_order_starts_as_placed(): void
    {
        $this->assertSame('placed', Order::create([
            'user_id' => User::factory()->create()->id,
            'total_price' => 100,
        ])->fresh()->status, 'The column default must match the lifecycle, not the old vocabulary.');
    }

    public function test_a_pickup_order_walks_the_pickup_chain(): void
    {
        $order = $this->order('pickup');

        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP, OrderStatus::COMPLETED] as $status) {
            $this->advance($order, $status)->assertOk();
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_a_delivery_order_walks_the_delivery_chain(): void
    {
        $order = $this->order('delivery');

        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::ON_THE_WAY, OrderStatus::DELIVERED] as $status) {
            $this->advance($order, $status)->assertOk();
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_a_pickup_order_cannot_be_sent_out_for_delivery(): void
    {
        $order = $this->order('pickup', OrderStatus::PREPARING);

        $this->advance($order, OrderStatus::ON_THE_WAY)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STATUS_TRANSITION');

        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    public function test_a_delivery_order_cannot_be_marked_ready_for_pickup(): void
    {
        $order = $this->order('delivery', OrderStatus::PREPARING);

        $this->advance($order, OrderStatus::READY_FOR_PICKUP)->assertStatus(422);
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $order = $this->order('pickup');

        $this->advance($order, OrderStatus::PREPARING)
            ->assertStatus(422)
            ->assertJsonPath('allowed', [OrderStatus::CONFIRMED, OrderStatus::CANCELLED]);
    }

    public function test_the_lifecycle_does_not_run_backwards(): void
    {
        $order = $this->order('pickup', OrderStatus::PREPARING);

        $this->advance($order, OrderStatus::CONFIRMED)->assertStatus(422);
    }

    public function test_a_finished_order_accepts_nothing_further(): void
    {
        $order = $this->order('pickup', OrderStatus::COMPLETED);

        $this->advance($order, OrderStatus::CANCELLED)
            ->assertStatus(422)
            ->assertJsonPath('allowed', []);
    }

    public function test_the_old_vocabulary_is_rejected(): void
    {
        $this->advance($this->order(), 'approved')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->advance($this->order(), 'pending')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_setting_the_status_it_already_holds_is_a_no_op(): void
    {
        $order = $this->order('pickup', OrderStatus::PREPARING);

        $this->advance($order, OrderStatus::PREPARING)->assertOk();
        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Cancellation (BR-19, BR-20)
    // -----------------------------------------------------------------

    public function test_an_agent_may_cancel_from_any_point_before_the_end(): void
    {
        foreach ([OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::ON_THE_WAY] as $from) {
            $order = $this->order('delivery', $from);

            $this->advance($order, OrderStatus::CANCELLED)->assertOk();
            $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status, "cancelling from {$from}");
        }
    }

    public function test_a_customer_may_cancel_their_own_order_only_before_confirmation(): void
    {
        $order = $this->order('pickup');
        $owner = $order->user;

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_customer_cannot_cancel_a_confirmed_order_themselves(): void
    {
        $order = $this->order('pickup', OrderStatus::CONFIRMED);

        $this->actingAs($order->user, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'CANCELLATION_REQUIRES_APPROVAL');

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status, 'BR-20: this is the agent’s decision.');
    }

    // -----------------------------------------------------------------
    // Queue entry (BR-22, FR-08.1)
    // -----------------------------------------------------------------

    public function test_placing_an_order_does_not_enter_the_queue(): void
    {
        $order = $this->order();

        $this->assertNull($order->queue_number, 'BR-22: the queue is entered at successful payment, not at checkout.');
        $this->assertNull($order->queued_at);
        $this->assertFalse($order->hasEnteredQueue());
    }

    public function test_entering_the_queue_assigns_a_number_a_date_and_a_timestamp(): void
    {
        $order = $this->order();

        $this->assertTrue($order->enterQueue());

        $order->refresh();

        $this->assertSame(1, $order->queue_number);
        $this->assertNotNull($order->queued_at);
        $this->assertSame(
            CarbonImmutable::now(config('store.timezone'))->toDateString(),
            $order->queue_date
        );
    }

    public function test_numbers_are_sequential_within_a_trading_day(): void
    {
        $numbers = collect(range(1, 3))->map(function () {
            $order = $this->order();
            $order->enterQueue();

            return $order->fresh()->queue_number;
        });

        $this->assertSame([1, 2, 3], $numbers->all(), 'FR-08.2');
    }

    public function test_entering_the_queue_twice_changes_nothing(): void
    {
        $order = $this->order();
        $order->enterQueue();

        $this->assertFalse($order->enterQueue(), 'A replayed payment webhook must not re-queue the order.');
        $this->assertSame(1, $order->fresh()->queue_number);
    }

    public function test_the_number_restarts_the_next_trading_day(): void
    {
        $today = $this->order();
        $today->enterQueue(CarbonImmutable::parse('2026-08-25 03:00:00', 'UTC'));

        $tomorrow = $this->order();
        $tomorrow->enterQueue(CarbonImmutable::parse('2026-08-26 03:00:00', 'UTC'));

        $this->assertSame(1, $today->fresh()->queue_number);
        $this->assertSame(1, $tomorrow->fresh()->queue_number);
    }

    public function test_the_trading_day_is_read_in_manila_not_utc(): void
    {
        $order = $this->order();

        // 16:30 UTC is 00:30 the next morning in Manila. Reading the app clock
        // would file this order under the wrong day and hand it number 1 in a
        // queue that already has customers waiting in it.
        $order->enterQueue(CarbonImmutable::parse('2026-08-25 16:30:00', 'UTC'));

        $this->assertSame('2026-08-26', $order->fresh()->queue_date);
    }

    public function test_two_orders_cannot_hold_the_same_number_on_the_same_day(): void
    {
        $first = $this->order();
        $first->enterQueue();
        $first->refresh();

        $second = $this->order();

        $this->expectException(QueryException::class);

        $second->forceFill([
            'queue_number' => $first->queue_number,
            'queue_date' => $first->queue_date,
            'queued_at' => now(),
        ])->save();
    }

    // -----------------------------------------------------------------
    // The vocabulary itself
    // -----------------------------------------------------------------

    public function test_the_chain_forks_on_fulfilment_type(): void
    {
        $this->assertSame(
            ['placed', 'confirmed', 'preparing', 'ready_for_pickup', 'completed'],
            OrderStatus::chain('pickup')
        );

        $this->assertSame(
            ['placed', 'confirmed', 'preparing', 'on_the_way', 'delivered'],
            OrderStatus::chain('delivery')
        );
    }

    public function test_an_order_with_no_fulfilment_type_falls_back_to_pickup(): void
    {
        $this->assertSame(OrderStatus::chain('pickup'), OrderStatus::chain(null));
    }

    public function test_a_status_from_the_wrong_branch_has_nowhere_to_go(): void
    {
        $this->assertNull(OrderStatus::next(OrderStatus::ON_THE_WAY, 'pickup'));
        $this->assertNull(OrderStatus::next(OrderStatus::READY_FOR_PICKUP, 'delivery'));
    }

    public function test_labels_are_readable(): void
    {
        $this->assertSame('Ready for pickup', OrderStatus::label(OrderStatus::READY_FOR_PICKUP));
        $this->assertSame('On the way', OrderStatus::label(OrderStatus::ON_THE_WAY));
    }
}
