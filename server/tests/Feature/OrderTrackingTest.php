<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer's own view of their orders (UC-ORD-010/011, BR-19, BR-30).
 *
 * The rules this pins are the ones the tracking screen would otherwise have to
 * re-implement in the browser: which step it is on, whether it may still be
 * cancelled, and how many orders are ahead of it.
 */
class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mid-morning, mid-week, store open.
     *
     * Placing an order goes through the real checkout gate, so without a pinned
     * clock this suite passes or fails depending on what time of day it is run.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', 'Asia/Manila')->utc());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER]);
    }

    private function order(User $owner, string $type = 'pickup', string $status = OrderStatus::PLACED, bool $queue = true): Order
    {
        $order = Order::create([
            'user_id' => $owner->id,
            'order_type' => $type,
            'status' => OrderStatus::PLACED,
            'subtotal' => 410,
            'delivery_fee' => $type === 'delivery' ? 30 : 0,
            'discount_amount' => 0,
            'total_amount' => $type === 'delivery' ? 440 : 410,
            'total_price' => $type === 'delivery' ? 440 : 410,
            'full_address' => $type === 'delivery' ? 'Apalit, Pampanga' : null,
        ]);

        if ($queue) {
            $order->enterQueue();
        }

        if ($status !== OrderStatus::PLACED) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->fresh();
    }

    // -----------------------------------------------------------------
    // The list
    // -----------------------------------------------------------------

    public function test_a_customer_with_no_orders_gets_an_empty_list_not_a_404(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('counts.active', 0);
    }

    public function test_the_list_returns_only_the_callers_own_orders(): void
    {
        $mine = $this->order($customer = $this->customer());
        $this->order($this->customer());

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_the_active_filter_keeps_orders_that_are_still_moving(): void
    {
        $customer = $this->customer();

        $this->order($customer, 'pickup', OrderStatus::PREPARING);
        // Out of the kitchen's line, but still very much the customer's live order.
        $this->order($customer, 'pickup', OrderStatus::READY_FOR_PICKUP);
        $this->order($customer, 'pickup', OrderStatus::COMPLETED);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/orders?filter=active')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.active', 2);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/orders?filter=past')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // -----------------------------------------------------------------
    // One order
    // -----------------------------------------------------------------

    public function test_the_tracker_reports_the_step_the_order_is_on(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::PREPARING);

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('order.step_index', 3)
            ->assertJsonPath('order.step_count', 5)
            ->assertJsonPath('order.steps.0.state', 'done')
            ->assertJsonPath('order.steps.1.state', 'done')
            ->assertJsonPath('order.steps.2.state', 'current')
            ->assertJsonPath('order.steps.3.state', 'upcoming')
            // The chain forks: a delivery never shows "ready for pickup".
            ->assertJsonPath('order.steps.3.key', OrderStatus::ON_THE_WAY)
            ->assertJsonPath('order.steps.4.key', OrderStatus::DELIVERED);
    }

    public function test_a_pickup_order_walks_the_pickup_chain(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::PREPARING);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.steps.3.key', OrderStatus::READY_FOR_PICKUP)
            ->assertJsonPath('order.steps.4.key', OrderStatus::COMPLETED);
    }

    public function test_a_finished_order_has_every_step_done(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::DELIVERED);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.steps.4.state', 'done')
            ->assertJsonPath('order.is_terminal', true)
            ->assertJsonPath('order.queue.in_line', false);
    }

    public function test_a_cancelled_order_lights_no_steps_at_all(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::CANCELLED);

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('order.is_cancelled', true)
            ->assertJsonPath('order.step_index', null);

        foreach (range(0, 4) as $index) {
            $response->assertJsonPath("order.steps.{$index}.state", 'upcoming');
        }
    }

    public function test_the_tracker_reports_the_position_in_the_line(): void
    {
        $customer = $this->customer();

        $this->order($this->customer());
        $this->order($this->customer());
        $mine = $this->order($customer);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('order.queue.in_line', true)
            ->assertJsonPath('order.queue.position', 3)
            ->assertJsonPath('order.queue.ahead', 2)
            ->assertJsonPath('order.queue.label', 'CNC-003');
    }

    public function test_an_order_the_kitchen_is_done_with_has_no_position(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::READY_FOR_PICKUP);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.queue.in_line', false)
            ->assertJsonPath('order.queue.ahead', null);
    }

    public function test_an_unpaid_order_is_referred_to_by_id_until_it_has_a_ticket(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::PLACED, queue: false);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.reference', '#'.$order->id)
            ->assertJsonPath('order.queue.label', null);
    }

    public function test_the_tracker_carries_the_items_and_the_money(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery');

        $food = Food::create([
            'thumbnail' => 'https://example.test/chicken.png',
            'food_name' => 'Herb Roasted Chicken',
            'price' => 350,
            'description' => 'Half herb.',
            'stock_quantity' => 10,
            'is_available' => true,
        ]);

        OrderItem::create(['order_id' => $order->id, 'food_id' => $food->id, 'quantity' => 1, 'price' => 350]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.items.0.food_name', 'Herb Roasted Chicken')
            ->assertJsonPath('order.subtotal', 410)
            ->assertJsonPath('order.delivery_fee', 30)
            ->assertJsonPath('order.total', 440)
            ->assertJsonPath('order.delivery.full_address', 'Apalit, Pampanga');
    }

    public function test_a_customer_cannot_read_someone_elses_order(): void
    {
        $order = $this->order($this->customer());

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_read_an_order(): void
    {
        $order = $this->order($this->customer());

        $this->getJson("/api/orders/{$order->id}")->assertUnauthorized();
    }

    public function test_the_staff_records_route_is_not_swallowed_by_the_tracking_route(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->getJson('/api/orders/all')
            ->assertOk()
            ->assertJsonStructure(['orders', 'current_page']);
    }

    // -----------------------------------------------------------------
    // Cancellation (BR-19, BR-20)
    // -----------------------------------------------------------------

    public function test_a_placed_order_can_still_be_cancelled_by_the_customer(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('order.can_cancel', true);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('order.status', OrderStatus::CANCELLED);
    }

    public function test_a_confirmed_order_reports_that_cancelling_now_needs_the_store(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::CONFIRMED);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('order.can_cancel', false);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'CANCELLATION_REQUIRES_APPROVAL');
    }

    // -----------------------------------------------------------------
    // Confirming receipt (BR-30, FR-02.10)
    // -----------------------------------------------------------------

    public function test_the_customer_confirms_their_delivery_arrived(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::ON_THE_WAY);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('order.can_confirm_receipt', true);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/received")
            ->assertOk()
            ->assertJsonPath('order.status', OrderStatus::DELIVERED);
    }

    public function test_there_is_nothing_to_confirm_before_it_is_on_its_way(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::PREPARING);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('order.can_confirm_receipt', false);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/received")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_ON_THE_WAY');
    }

    public function test_a_pickup_order_is_never_confirmed_as_received(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::READY_FOR_PICKUP);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/received")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_A_DELIVERY');
    }

    public function test_a_stranger_cannot_confirm_someone_elses_delivery(): void
    {
        $order = $this->order($this->customer(), 'delivery', OrderStatus::ON_THE_WAY);

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/received")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Add-ons survive checkout (BR-17)
    // -----------------------------------------------------------------

    public function test_the_addons_a_line_was_bought_with_reach_the_order(): void
    {
        $customer = $this->customer();

        $food = Food::create([
            'food_name' => 'Herb Roasted Chicken',
            'description' => 'Whole.',
            'price' => 350,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'prep_time' => 20,
        ]);

        $addon = Addon::create([
            'addon_name' => 'Half Herb',
            'addon_price' => 60,
            'availability' => true,
            'addon_group' => 'Sides',
        ]);

        $food->addons()->attach($addon->id);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 1, 'addon_ids' => [$addon->id]])
            ->assertCreated();

        $placed = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', [
                'fulfilment_type' => 'pickup',
                'pickup_at' => now(config('store.timezone'))->addHour()->toIso8601String(),
                'contact_name' => 'Julia Veneese',
                'contact_phone' => '09171234567',
            ])
            ->assertCreated()
            ->json('order.id');

        // The price already folded the add-on in; what was missing was any
        // record of WHICH add-on, which the receipt and the kitchen both need.
        $this->assertDatabaseHas('order_item_addons', ['addon_id' => $addon->id]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$placed}")
            ->assertOk()
            ->assertJsonPath('order.items.0.addons.0.addon_name', 'Half Herb')
            ->assertJsonPath('order.items.0.subtotal', 410);
    }

    // -----------------------------------------------------------------
    // closed_at
    // -----------------------------------------------------------------

    public function test_reaching_a_terminal_state_stamps_when_it_happened(): void
    {
        $order = $this->order($this->customer(), 'pickup', OrderStatus::READY_FOR_PICKUP);

        $this->assertNull($order->closed_at);

        $order->forceFill(['status' => OrderStatus::COMPLETED])->save();

        $this->assertNotNull($order->fresh()->closed_at);
    }

    public function test_a_cancellation_is_stamped_too(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk();

        $this->assertNotNull($order->fresh()->closed_at);
    }

    public function test_a_later_edit_does_not_move_the_closing_time(): void
    {
        $order = $this->order($this->customer(), 'pickup', OrderStatus::COMPLETED);
        $closed = $order->fresh()->closed_at;

        $order->forceFill(['estimated_time_of_completion' => 25])->save();

        $this->assertEquals($closed, $order->fresh()->closed_at);
    }
}
