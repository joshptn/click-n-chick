<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Database\Seeders\AddonSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\FoodSeeder;
use Database\Seeders\OrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo seeder is the only route to the tracker until payment exists, so it
 * is worth knowing it actually produces what it claims - a reachable order in
 * every state the screen renders differently.
 */
class OrderSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'email' => 'customer@chicknclick.test',
        ]);

        $this->seed([CategorySeeder::class, AddonSeeder::class, FoodSeeder::class, OrderSeeder::class]);

        return $customer;
    }

    public function test_it_produces_an_order_in_every_state_the_tracker_renders(): void
    {
        $customer = $this->seedDemo();

        $statuses = Order::where('user_id', $customer->id)->pluck('status')->unique()->values()->all();

        foreach ([
            OrderStatus::PLACED,
            OrderStatus::CONFIRMED,
            OrderStatus::PREPARING,
            OrderStatus::READY_FOR_PICKUP,
            OrderStatus::ON_THE_WAY,
            OrderStatus::DELIVERED,
            OrderStatus::COMPLETED,
            OrderStatus::CANCELLED,
        ] as $status) {
            $this->assertContains($status, $statuses, "no seeded order is {$status}");
        }
    }

    public function test_the_seeded_orders_are_reachable_on_the_tracker(): void
    {
        $customer = $this->seedDemo();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.food_name', fn ($name) => is_string($name) && $name !== '');

        $order = Order::where('user_id', $customer->id)
            ->where('status', OrderStatus::PREPARING)
            ->firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.cancellation.refundable', false)
            ->assertJsonPath('order.queue.in_line', true);
    }

    public function test_the_customers_order_has_other_people_ahead_of_it(): void
    {
        $customer = $this->seedDemo();

        $order = Order::where('user_id', $customer->id)
            ->where('status', OrderStatus::PREPARING)
            ->firstOrFail();

        // Filler orders exist so the position line has something real to count
        // rather than always reading "we're starting your order now".
        $this->assertGreaterThan(0, $order->aheadInQueue());
    }

    public function test_a_past_order_is_dated_in_the_past(): void
    {
        $customer = $this->seedDemo();

        $delivered = Order::where('user_id', $customer->id)
            ->where('status', OrderStatus::DELIVERED)
            ->firstOrFail();

        // created_at is not fillable, so without an explicit write every
        // "past" order would be stamped now and the history would read as if
        // it all happened this minute.
        // Two hours is enough to prove created_at was honoured rather than
        // stamped with now(). A larger margin would be brittle: "yesterday at
        // 5:30 PM" is only nine hours ago when the suite runs at 2 AM.
        $this->assertTrue($delivered->created_at->lessThan(now()->subHours(2)));
        $this->assertNotNull($delivered->closed_at);
        $this->assertTrue($delivered->closed_at->greaterThan($delivered->created_at));
    }

    public function test_the_unpaid_order_holds_no_queue_ticket(): void
    {
        $customer = $this->seedDemo();

        $unpaid = Order::where('user_id', $customer->id)
            ->where('payment_status', 'unpaid')
            ->firstOrFail();

        $this->assertNull($unpaid->queue_number, 'BR-22: no payment, no ticket.');
        $this->assertSame('#'.$unpaid->id, $unpaid->reference());
    }

    public function test_running_it_twice_replaces_rather_than_duplicates(): void
    {
        $customer = $this->seedDemo();

        $before = Order::where('user_id', $customer->id)->count();

        $this->seed([OrderSeeder::class]);

        $this->assertSame($before, Order::where('user_id', $customer->id)->count());
    }

    public function test_a_cancelled_order_carries_the_reason_the_customer_reads(): void
    {
        $customer = $this->seedDemo();

        $cancelled = Order::where('user_id', $customer->id)
            ->where('status', OrderStatus::CANCELLED)
            ->firstOrFail();

        $this->assertNotEmpty($cancelled->cancellation_reason);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$cancelled->id}")
            ->assertOk()
            ->assertJsonPath('order.cancellation_reason', $cancelled->cancellation_reason)
            ->assertJsonPath('order.is_cancelled', true);
    }
}
