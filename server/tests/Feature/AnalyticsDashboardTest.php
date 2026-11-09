<?php

namespace Tests\Feature;

use App\Events\OrderBroadcast;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Analytics Dashboard (UC-REP-001): cards from the live rows, and sales
 * that agree with the Daily Sales Report.
 */
class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-09';

    private const NOW = '2026-11-09 18:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->at(self::NOW));
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Manila')->utc();
    }

    private function user(string $role = User::ROLE_CUSTOMER): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function manager(): static
    {
        return $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum');
    }

    private function analytics(): TestResponse
    {
        return $this->manager()->getJson('/api/admin/reports/analytics')->assertOk();
    }

    private function operations(): TestResponse
    {
        return $this->manager()->getJson('/api/admin/reports/operations')->assertOk();
    }

    private function placed(string $placedAt, array $attributes = []): Order
    {
        $this->travelTo($this->at($placedAt));

        $total = $attributes['total_amount'] ?? 300;
        $order = Order::create($attributes + [
            'user_id' => $this->user()->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => $total,
            'discount_amount' => 0,
            'delivery_fee' => 0,
            'total_price' => $total,
            'total_amount' => $total,
            'payment_status' => 'unpaid',
        ]);

        $this->travelTo($this->at(self::NOW));

        return $order;
    }

    private function closed(string $status, string $closedAt, float $total = 300, array $attributes = []): Order
    {
        $order = $this->placed(
            CarbonImmutable::parse($closedAt, 'Asia/Manila')->subMinutes(30)->format('Y-m-d H:i:s'),
            $attributes + ['total_amount' => $total, 'total_price' => $total, 'subtotal' => $total]
        );

        $this->travelTo($this->at($closedAt));
        $order->forceFill(['status' => $status])->save();
        $this->travelTo($this->at(self::NOW));

        return $order->fresh();
    }

    private function onBoard(string $status, array $attributes = []): Order
    {
        $order = $this->placed('2026-11-09 17:00:00', $attributes + ['queue_date' => self::TODAY, 'queued_at' => $this->at('2026-11-09 17:00:00')]);

        $order->forceFill(['status' => $status])->save();

        return $order;
    }

    private function food(string $name): Food
    {
        return Food::create([
            'thumbnail' => 'https://example.test/a.png',
            'food_name' => $name,
            'price' => 100,
            'description' => 'A dish.',
            'is_available' => true,
        ]);
    }

    private function line(Order $order, Food $food, int $quantity, float $subtotal): void
    {
        OrderItem::create(['order_id' => $order->id, 'food_id' => $food->id, 'quantity' => $quantity, 'price' => $subtotal / $quantity, 'subtotal' => $subtotal]);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_only_the_store_manager_may_read_the_dashboard(): void
    {
        $urls = ['/api/admin/reports/analytics', '/api/admin/reports/operations'];

        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        foreach ($urls as $url) {
            $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')->getJson($url)->assertForbidden();
            $this->actingAs($this->user(), 'sanctum')->getJson($url)->assertForbidden();
            $this->manager()->getJson($url)->assertOk();
        }
    }

    public function test_the_store_manager_still_cannot_work_an_order(): void
    {
        $order = $this->onBoard(OrderStatus::PLACED);

        $this->manager()->postJson("/api/agent/orders/{$order->id}/advance")->assertForbidden();

        $this->assertSame(OrderStatus::PLACED, $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // The four cards
    // -----------------------------------------------------------------

    public function test_new_orders_are_every_order_placed_today_and_the_rest_split_it(): void
    {
        $this->placed('2026-11-09 09:00:00');
        $this->placed('2026-11-09 10:00:00', ['order_type' => 'delivery']);
        $this->placed('2026-11-09 11:00:00', ['status' => OrderStatus::SUBMITTED, 'scheduled_for' => $this->at('2026-11-12 12:00:00')]);
        $this->placed('2026-11-08 23:59:00');

        $this->operations()
            ->assertJsonPath('new_orders.today', 3);
    }

    public function test_delivery_and_pickup_lead_with_what_is_still_to_be_handed_over(): void
    {
        $delivery = ['order_type' => 'delivery'];

        $this->onBoard(OrderStatus::PLACED);
        $this->onBoard(OrderStatus::CONFIRMED);
        $this->onBoard(OrderStatus::PREPARING);
        $this->onBoard(OrderStatus::READY_FOR_PICKUP);
        $this->onBoard(OrderStatus::COMPLETED);
        $this->onBoard(OrderStatus::CANCELLED);
        $this->onBoard(OrderStatus::PREPARING, $delivery);
        $this->onBoard(OrderStatus::ON_THE_WAY, $delivery);
        $this->onBoard(OrderStatus::DELIVERED, $delivery);
        $this->onBoard(OrderStatus::PREPARING, ['queue_date' => '2026-11-08']);

        $this->operations()
            ->assertJsonPath('pickup.open', 4)
            ->assertJsonPath('delivery.open', 2);
    }

    public function test_completed_counts_orders_handed_over_today_whenever_they_were_placed(): void
    {
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00');
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 13:00:00');
        $this->closed(OrderStatus::DELIVERED, '2026-11-09 12:00:00', 300, ['order_type' => 'delivery']);
        $this->closed(OrderStatus::CANCELLED, '2026-11-09 12:00:00');
        $this->closed(OrderStatus::COMPLETED, '2026-11-08 12:00:00');
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00', 300, ['scheduled_for' => $this->at('2026-11-09 12:00:00')]);

        $this->operations()
            ->assertJsonPath('pickup.completed', 3)
            ->assertJsonPath('delivery.delivered', 1);
    }

    public function test_new_orders_shows_how_today_s_orders_have_ended_so_far(): void
    {
        $this->placed('2026-11-09 09:00:00');
        $this->placed('2026-11-09 09:00:00', ['status' => OrderStatus::COMPLETED]);
        $this->placed('2026-11-09 09:00:00', ['status' => OrderStatus::DELIVERED, 'order_type' => 'delivery']);
        $this->placed('2026-11-09 09:00:00', ['status' => OrderStatus::CANCELLED]);
        $this->placed('2026-11-08 09:00:00', ['status' => OrderStatus::COMPLETED]);

        $this->operations()
            ->assertJsonPath('new_orders.today', 4)
            ->assertJsonPath('new_orders.completed', 2)
            ->assertJsonPath('new_orders.cancelled', 1);
    }

    public function test_preparing_pending_is_confirmed_orders_the_kitchen_has_not_started(): void
    {
        $this->onBoard(OrderStatus::PLACED);
        $this->onBoard(OrderStatus::CONFIRMED);
        $this->onBoard(OrderStatus::CONFIRMED);
        $this->onBoard(OrderStatus::PREPARING);
        $this->onBoard(OrderStatus::ON_THE_WAY, ['order_type' => 'delivery']);
        $this->onBoard(OrderStatus::PREPARING, ['queue_date' => '2026-11-08']);
        $this->onBoard(OrderStatus::CONFIRMED, ['queue_date' => '2026-11-08']);

        $this->operations()
            ->assertJsonPath('preparing.count', 1)
            ->assertJsonPath('preparing.pending', 2);
    }

    public function test_an_advance_order_counts_as_preparing_once_in_the_kitchen(): void
    {
        $this->placed('2026-11-09 10:00:00', ['status' => OrderStatus::PREPARING, 'scheduled_for' => $this->at('2026-11-09 19:00:00')]);
        $this->placed('2026-11-09 10:00:00', ['status' => OrderStatus::SCHEDULED, 'scheduled_for' => $this->at('2026-11-10 19:00:00')]);

        $this->operations()->assertJsonPath('preparing.count', 1);
    }

    public function test_advance_due_today_covers_only_today_s_collections(): void
    {
        $today = ['scheduled_for' => $this->at('2026-11-09 19:00:00')];
        $later = ['scheduled_for' => $this->at('2026-11-12 12:00:00')];
        $earlier = ['scheduled_for' => $this->at('2026-11-07 12:00:00')];
        $at = '2026-11-09 10:00:00';

        $this->placed($at, $today + ['status' => OrderStatus::CONFIRMED]);
        $this->placed($at, $today + ['status' => OrderStatus::SCHEDULED]);
        $this->placed($at, $later + ['status' => OrderStatus::SCHEDULED]);
        $this->placed($at, $earlier + ['status' => OrderStatus::SCHEDULED]);
        $this->placed($at, $today + ['status' => OrderStatus::PREPARING]);
        $this->placed($at, $today + ['status' => OrderStatus::READY_FOR_PICKUP]);
        $this->placed($at, $earlier + ['status' => OrderStatus::PREPARING]);

        $this->operations()
            ->assertJsonPath('advance.due_today', 4)
            ->assertJsonPath('advance.preparing', 1)
            ->assertJsonPath('preparing.count', 1)
            ->assertJsonPath('pickup.open', 2);
    }

    public function test_only_advance_orders_collected_today_are_counted_as_collected(): void
    {
        $scheduled = ['scheduled_for' => $this->at('2026-11-09 12:00:00')];

        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:30:00', 300, $scheduled);
        $this->closed(OrderStatus::COMPLETED, '2026-11-08 12:30:00', 300, ['scheduled_for' => $this->at('2026-11-08 12:00:00')]);
        $this->closed(OrderStatus::CANCELLED, '2026-11-09 12:30:00', 300, $scheduled);
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:30:00');

        $this->operations()->assertJsonPath('advance.completed', 1);
    }

    public function test_advance_to_pay_counts_every_unpaid_request_however_it_was_accepted(): void
    {
        $later = ['scheduled_for' => $this->at('2026-11-12 12:00:00')];

        $this->placed('2026-11-09 10:00:00', $later + ['status' => OrderStatus::AWAITING_PAYMENT, 'accepted_at' => $this->at('2026-11-09 10:00:00')]);
        $this->placed('2026-11-08 18:00:00', $later + ['status' => OrderStatus::ACCEPTED, 'accepted_at' => $this->at('2026-11-08 19:00:00')]);
        $this->placed('2026-11-09 10:00:00', $later + ['status' => OrderStatus::SCHEDULED, 'accepted_at' => $this->at('2026-11-09 11:00:00')]);
        $this->placed('2026-11-09 10:00:00', $later + ['status' => OrderStatus::SUBMITTED]);

        $this->operations()
            ->assertJsonPath('advance.to_pay', 2)
            ->assertJsonPath('advance.accepted', 2);
    }

    public function test_only_a_payment_record_made_today_counts_an_advance_order_as_paid(): void
    {
        $later = ['scheduled_for' => $this->at('2026-11-12 12:00:00')];
        $pay = fn (Order $order, string $at) => Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'gcash',
            'payment_status' => Payment::STATUS_PAID,
            'paid_at' => $this->at($at),
        ]);

        $paid = $this->placed('2026-11-09 10:00:00', $later + ['status' => OrderStatus::SCHEDULED]);
        $pay($paid, '2026-11-09 11:00:00');
        $pay($paid, '2026-11-09 11:05:00');
        $pay($this->placed('2026-11-08 10:00:00', $later + ['status' => OrderStatus::SCHEDULED]), '2026-11-08 11:00:00');
        $pay($this->placed('2026-11-09 10:00:00'), '2026-11-09 11:00:00');
        $this->placed('2026-11-09 10:00:00', $later + ['status' => OrderStatus::SCHEDULED, 'payment_status' => 'paid']);

        $this->operations()->assertJsonPath('advance.paid', 1);
    }

    // -----------------------------------------------------------------
    // Sales
    // -----------------------------------------------------------------

    public function test_the_chart_matches_the_daily_sales_report(): void
    {
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00', 300.25);
        $this->closed(OrderStatus::DELIVERED, '2026-11-09 13:00:00', 455.50, ['order_type' => 'delivery']);

        $total = $this->manager()->getJson('/api/admin/reports/daily-sales?date='.self::TODAY)->assertOk()->json('fulfilled.total');
        $sales = $this->analytics()->json('sales');

        $this->assertEquals(755.75, $total);

        foreach (['weekly', 'daily', 'hourly'] as $view) {
            $this->assertEquals($total, round(array_sum(array_column($sales[$view], 'value')), 2), $view);
            $this->assertSame(2, array_sum(array_column($sales[$view], 'orders')), $view);
        }
    }

    public function test_cancelled_declined_and_open_orders_are_never_sales(): void
    {
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00', 100);
        $this->closed(OrderStatus::CANCELLED, '2026-11-09 12:00:00', 200);
        $this->closed(OrderStatus::REJECTED, '2026-11-09 12:00:00', 300, ['scheduled_for' => $this->at('2026-11-12 12:00:00')]);
        $this->placed('2026-11-09 12:00:00', ['total_amount' => 400]);

        $hourly = $this->analytics()->json('sales.hourly');

        $this->assertEquals(100.0, array_sum(array_column($hourly, 'value')));
        $this->assertSame(1, array_sum(array_column($hourly, 'orders')));
    }

    public function test_each_view_covers_its_own_window(): void
    {
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00', 10);
        $this->closed(OrderStatus::COMPLETED, '2026-11-03 12:00:00', 20);
        $this->closed(OrderStatus::COMPLETED, '2026-11-02 12:00:00', 40);
        $this->closed(OrderStatus::COMPLETED, '2026-10-11 12:00:00', 80);
        $this->closed(OrderStatus::COMPLETED, '2026-10-10 12:00:00', 160);

        $sales = $this->analytics()->json('sales');

        $this->assertCount(7, $sales['weekly']);
        $this->assertSame('2026-11-03', $sales['weekly'][0]['key']);
        $this->assertEquals(30.0, array_sum(array_column($sales['weekly'], 'value')));

        $this->assertCount(30, $sales['daily']);
        $this->assertSame('2026-10-11', $sales['daily'][0]['key']);
        $this->assertEquals(150.0, array_sum(array_column($sales['daily'], 'value')));

        $this->assertEquals(10.0, array_sum(array_column($sales['hourly'], 'value')));
    }

    public function test_sales_are_filed_under_the_store_day_and_hour_they_closed(): void
    {
        // 23:30 and 00:30 Manila: either side of midnight there, one UTC day.
        $this->closed(OrderStatus::COMPLETED, '2026-11-08 23:30:00', 100);
        $this->closed(OrderStatus::COMPLETED, '2026-11-09 00:30:00', 200);

        $sales = $this->analytics()->json('sales');
        $daily = collect($sales['daily'])->keyBy('key');

        $this->assertEquals(100.0, $daily['2026-11-08']['value']);
        $this->assertEquals(200.0, $daily['2026-11-09']['value']);
        $this->assertEquals(200.0, collect($sales['hourly'])->keyBy('key')[0]['value']);
    }

    public function test_hours_are_trimmed_to_trading_hours(): void
    {
        $hours = array_column($this->analytics()->json('sales.hourly'), 'key');

        $this->assertSame(range(7, 20), $hours);
    }

    public function test_top_five_products_come_from_the_last_seven_days_of_fulfilled_orders(): void
    {
        $sold = $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00');
        $old = $this->closed(OrderStatus::COMPLETED, '2026-11-02 12:00:00');
        $cancelled = $this->closed(OrderStatus::CANCELLED, '2026-11-09 12:00:00');
        $roast = $this->food('Roast');

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $index => $name) {
            $this->line($sold, $this->food($name), $index + 1, 100 * ($index + 1));
        }

        $this->line($old, $roast, 90, 9000);
        $this->line($cancelled, $roast, 90, 9000);

        $products = $this->analytics()->json('products');

        $this->assertSame(['F', 'E', 'D', 'C', 'B'], array_column($products, 'food_name'));
        $this->assertSame(6, $products[0]['quantity']);
        $this->assertEquals(600.0, $products[0]['sales']);
        $this->assertArrayNotHasKey('is_best_seller', $products[0]);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_orders(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->analytics();
            $this->operations();

            return count(DB::getQueryLog());
        };

        $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00');
        $few = $count();

        foreach (range(1, 8) as $ignored) {
            $this->closed(OrderStatus::COMPLETED, '2026-11-09 12:00:00');
            $this->onBoard(OrderStatus::PREPARING);
        }

        $this->assertLessThanOrEqual($few + 2, $count());
    }

    // -----------------------------------------------------------------
    // Realtime
    // -----------------------------------------------------------------

    public function test_an_order_closing_is_announced_to_staff_with_its_status(): void
    {
        Event::fake([OrderBroadcast::class]);

        $order = $this->onBoard(OrderStatus::READY_FOR_PICKUP);
        $order->forceFill(['status' => OrderStatus::COMPLETED])->save();

        app(OrderAnnouncer::class)->statusChanged($order);

        Event::assertDispatched(OrderBroadcast::class, function (OrderBroadcast $event) use ($order) {
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->order->is($order)
                && in_array('private-admin.orders', $channels, true)
                && $event->broadcastWith()['order']['status'] === OrderStatus::COMPLETED;
        });
    }

    public function test_figures_change_as_soon_as_an_order_closes(): void
    {
        $order = $this->onBoard(OrderStatus::PREPARING);

        $this->operations()->assertJsonPath('preparing.count', 1);
        $this->assertSame(0, array_sum(array_column($this->analytics()->json('sales.hourly'), 'orders')));

        $order->forceFill(['status' => OrderStatus::COMPLETED])->save();

        $this->operations()->assertJsonPath('preparing.count', 0);
        $this->assertSame(1, array_sum(array_column($this->analytics()->json('sales.hourly'), 'orders')));
    }
}
