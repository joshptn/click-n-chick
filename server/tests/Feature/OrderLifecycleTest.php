<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The canonical order lifecycle (BRD 3.1) and the daily order number.
 *
 * Two things are being pinned here. First, that the branch is real: a pickup
 * order and a delivery order genuinely walk different chains, and neither can
 * be pushed onto the other's terminal state. Second, that an immediate order
 * is numbered once, at payment, counting from 001 each Manila day (decided
 * 2026-11-20; two payments at the same instant may share a number).
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

        $this->advance($order, OrderStatus::PREPARING)
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

            // Through the cancel action, which takes the reason and refund
            // choice (BR-21a); the status route refuses a cancellation.
            $this->actingAs($this->agent(), 'sanctum')
                ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Kitchen closed early.', 'refund' => true])
                ->assertOk();
            $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status, "cancelling from {$from}");
        }

        $this->advance($this->order('delivery', OrderStatus::CONFIRMED), OrderStatus::CANCELLED)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'USE_CANCEL_ACTION');
    }

    public function test_a_customer_may_cancel_their_own_order(): void
    {
        $order = $this->order('pickup');
        $owner = $order->user;

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_customer_cannot_cancel_after_confirmation(): void
    {
        $order = $this->order('pickup', OrderStatus::CONFIRMED);

        // BR-19 (decided 2026-11-17): once a Store Agent confirms it, no longer.
        $this->actingAs($order->user, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'STORE_CONFIRMED');

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_a_closed_order_can_no_longer_be_cancelled(): void
    {
        $order = $this->order('pickup', OrderStatus::COMPLETED);

        $this->actingAs($order->user, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'ORDER_ALREADY_CLOSED');

        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Daily order numbers (decided 2026-11-20)
    // -----------------------------------------------------------------

    /** An immediate order created at a moment (UTC), not yet numbered. */
    private function createdAt(string $utc): Order
    {
        $order = $this->order();
        $order->forceFill(['created_at' => CarbonImmutable::parse($utc, 'UTC')])->save();

        return $order->fresh();
    }

    public function test_a_time_with_an_offset_is_stored_as_that_moment(): void
    {
        // Fixed 2026-11-20: 3:00 PM in Manila was stored as 15:00 UTC.
        $order = $this->order();
        $order->forceFill(['pickup_at' => '2026-11-20T15:00:00+08:00'])->save();

        $this->assertSame('2026-11-20 07:00:00', $order->fresh()->getRawOriginal('pickup_at'));
        $this->assertSame('3:00 PM', $order->fresh()->pickup_at->setTimezone('Asia/Manila')->format('g:i A'));
    }

    public function test_placing_an_order_gives_it_no_number(): void
    {
        $this->assertNull($this->order()->order_number, 'The number comes with the payment, not the checkout.');
    }

    public function test_numbers_are_plain_padded_and_sequential_within_a_day(): void
    {
        $numbers = collect(range(1, 3))->map(function () {
            $order = $this->order();
            $order->assignOrderNumber();

            return $order->fresh()->order_number;
        })->all();

        $this->assertSame(['001', '002', '003'], $numbers);
    }

    public function test_numbering_twice_changes_nothing(): void
    {
        $order = $this->order();
        $order->assignOrderNumber();

        $this->assertFalse($order->assignOrderNumber(), 'A replayed payment webhook must not renumber the order.');
        $this->assertSame('001', $order->fresh()->order_number);
    }

    public function test_the_number_restarts_the_next_day(): void
    {
        $today = $this->createdAt('2026-08-25 03:00:00');
        $today->assignOrderNumber();

        $tomorrow = $this->createdAt('2026-08-26 03:00:00');
        $tomorrow->assignOrderNumber();

        $this->assertSame('001', $today->fresh()->order_number);
        $this->assertSame('001', $tomorrow->fresh()->order_number);
    }

    public function test_the_day_is_the_manila_day_the_order_was_created(): void
    {
        // 16:30 UTC is 00:30 the next morning in Manila: a new day's first order.
        $earlier = $this->createdAt('2026-08-25 15:00:00');
        $earlier->assignOrderNumber();

        $afterMidnight = $this->createdAt('2026-08-25 16:30:00');
        $afterMidnight->assignOrderNumber();

        $this->assertSame('001', $afterMidnight->fresh()->order_number);
    }

    public function test_the_count_goes_past_nine_hundred_ninety_nine(): void
    {
        $order = $this->order();
        $order->forceFill(['order_number' => '999'])->save();

        $next = $this->order();
        $next->assignOrderNumber();

        $this->assertSame('1000', $next->fresh()->order_number);
    }

    public function test_an_order_is_called_by_its_number_or_its_id_until_paid(): void
    {
        $order = $this->order();

        $this->assertSame('#'.$order->id, $order->reference());

        $order->assignOrderNumber();

        $this->assertSame('001', $order->fresh()->reference());
        $this->assertSame('Order 001', $order->fresh()->title());
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
