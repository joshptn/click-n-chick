<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pending Orders: the confirmed orders one shift hands to the next, by when
 * each is needed.
 */
class PendingOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/agent/pending-orders';

    private const TODAY = '2026-11-09';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->at('12:00:00'));
    }

    private function at(string $time, string $date = self::TODAY): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", 'Asia/Manila')->utc();
    }

    private function user(string $role = User::ROLE_ADMIN): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function pending(): TestResponse
    {
        return $this->actingAs($this->user(), 'sanctum')->getJson(self::URL)->assertOk();
    }

    private function order(string $status, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'user_id' => $this->user(User::ROLE_CUSTOMER)->id,
            'order_type' => 'pickup',
            'status' => $status,
            'subtotal' => 300,
            'total_price' => 300,
            'total_amount' => 300,
        ]);
    }

    /** A regular order on a day's queue. */
    private function queued(string $status, array $attributes = [], string $date = self::TODAY): Order
    {
        static $number = 0;

        return $this->order($status, $attributes + [
            'queue_date' => $date,
            'queue_number' => ++$number,
            'queued_at' => $this->at('09:00:00', $date),
        ]);
    }

    private function ids(TestResponse $response, string $group): array
    {
        return array_column($response->json("groups.{$group}"), 'id');
    }

    public function test_both_staff_roles_may_read_it_and_nobody_else(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs($this->user(User::ROLE_CUSTOMER), 'sanctum')->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')->getJson(self::URL)->assertOk();
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')->getJson(self::URL)->assertOk();
    }

    public function test_the_store_manager_still_cannot_move_a_pending_order(): void
    {
        $order = $this->queued(OrderStatus::CONFIRMED);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/advance")
            ->assertForbidden();

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_only_confirmed_orders_on_today_s_queue_are_pending(): void
    {
        $confirmed = $this->queued(OrderStatus::CONFIRMED);

        foreach ([OrderStatus::PLACED, OrderStatus::READY_FOR_PICKUP, OrderStatus::COMPLETED, OrderStatus::CANCELLED] as $status) {
            $this->queued($status);
        }

        $response = $this->pending();

        $this->assertSame([$confirmed->id], $this->ids($response, 'now'));
        $response->assertJsonPath('groups.later', [])->assertJsonPath('groups.earlier', []);
    }

    public function test_an_order_is_due_now_within_the_kitchen_lead_time(): void
    {
        $soon = $this->queued(OrderStatus::CONFIRMED, ['pickup_at' => $this->at('12:20:00')]);
        $later = $this->queued(OrderStatus::CONFIRMED, ['pickup_at' => $this->at('12:21:00')]);

        $response = $this->pending();

        $this->assertSame([$soon->id], $this->ids($response, 'now'));
        $this->assertSame([$later->id], $this->ids($response, 'later'));
    }

    public function test_a_past_pickup_time_stays_due_now(): void
    {
        $late = $this->queued(OrderStatus::CONFIRMED, ['pickup_at' => $this->at('11:00:00')]);

        $this->assertSame([$late->id], $this->ids($this->pending(), 'now'));
    }

    public function test_a_delivery_has_no_set_time_so_it_is_due_now(): void
    {
        $delivery = $this->queued(OrderStatus::CONFIRMED, ['order_type' => 'delivery']);

        $response = $this->pending();

        $this->assertSame([$delivery->id], $this->ids($response, 'now'));
        $response->assertJsonPath('groups.now.0.needed_at', null);
    }

    public function test_later_orders_are_earliest_first(): void
    {
        $six = $this->queued(OrderStatus::CONFIRMED, ['pickup_at' => $this->at('18:00:00')]);
        $five = $this->queued(OrderStatus::CONFIRMED, ['pickup_at' => $this->at('17:00:00')]);

        $this->assertSame([$five->id, $six->id], $this->ids($this->pending(), 'later'));
    }

    public function test_today_s_advance_orders_are_included_and_other_days_are_not(): void
    {
        $confirmed = $this->order(OrderStatus::CONFIRMED, ['scheduled_for' => $this->at('17:00:00')]);
        $scheduled = $this->order(OrderStatus::SCHEDULED, ['scheduled_for' => $this->at('18:00:00')]);
        $this->order(OrderStatus::SCHEDULED, ['scheduled_for' => $this->at('17:00:00', '2026-11-10')]);
        $this->order(OrderStatus::AWAITING_PAYMENT, ['scheduled_for' => $this->at('17:00:00')]);
        $this->order(OrderStatus::SUBMITTED, ['scheduled_for' => $this->at('17:00:00')]);

        $response = $this->pending();

        $this->assertSame([$confirmed->id, $scheduled->id], $this->ids($response, 'later'));
        $response->assertJsonPath('groups.later.0.is_advance', true);
    }

    public function test_an_advance_order_due_soon_needs_starting_now(): void
    {
        $order = $this->order(OrderStatus::SCHEDULED, ['scheduled_for' => $this->at('12:10:00')]);

        $this->assertSame([$order->id], $this->ids($this->pending(), 'now'));
    }

    public function test_orders_left_open_from_earlier_days_are_listed_apart(): void
    {
        $regular = $this->queued(OrderStatus::PREPARING, [], '2026-11-08');
        $advance = $this->order(OrderStatus::SCHEDULED, ['scheduled_for' => $this->at('17:00:00', '2026-11-07')]);
        $this->queued(OrderStatus::COMPLETED, [], '2026-11-08');
        $this->queued(OrderStatus::CANCELLED, [], '2026-11-08');

        $response = $this->pending();

        $this->assertEqualsCanonicalizing([$regular->id, $advance->id], $this->ids($response, 'earlier'));
        $response->assertJsonPath('summary.earlier', 2)->assertJsonPath('summary.now', 0);
    }

    public function test_orders_being_prepared_today_have_their_own_group(): void
    {
        $regular = $this->queued(OrderStatus::PREPARING, ['pickup_at' => $this->at('17:00:00')]);
        $advance = $this->order(OrderStatus::PREPARING, ['scheduled_for' => $this->at('16:00:00')]);
        $earlier = $this->queued(OrderStatus::PREPARING, [], '2026-11-08');
        $this->order(OrderStatus::PREPARING, ['scheduled_for' => $this->at('17:00:00', '2026-11-10')]);
        $this->queued(OrderStatus::READY_FOR_PICKUP);

        $response = $this->pending();

        $this->assertSame([$advance->id, $regular->id], $this->ids($response, 'preparing'));
        $this->assertSame([$earlier->id], $this->ids($response, 'earlier'));
        $response->assertJsonPath('summary.preparing', 2)->assertJsonPath('summary.later', 0);
    }

    public function test_a_row_says_what_the_next_step_is(): void
    {
        $this->queued(OrderStatus::CONFIRMED, ['pickup_at' => $this->at('17:00:00')]);
        $this->order(OrderStatus::SCHEDULED, ['scheduled_for' => $this->at('12:10:00')]);
        $this->queued(OrderStatus::PREPARING);

        $response = $this->pending();

        $response->assertJsonPath('groups.later.0.next_status', OrderStatus::PREPARING);
        $response->assertJsonPath('groups.now.0.next_status', OrderStatus::PREPARING);
        $response->assertJsonPath('groups.preparing.0.next_status', OrderStatus::READY_FOR_PICKUP);
    }

    public function test_a_row_carries_what_the_kitchen_needs(): void
    {
        $order = $this->queued(OrderStatus::CONFIRMED, ['pickup_at' => $this->at('17:00:00')]);
        $food = Food::create(['thumbnail' => 'https://example.test/a.png', 'food_name' => 'Roast', 'price' => 100, 'description' => 'A dish.', 'is_available' => true]);

        OrderItem::create(['order_id' => $order->id, 'food_id' => $food->id, 'quantity' => 2, 'price' => 100, 'subtotal' => 200]);

        $row = $this->pending()->json('groups.later.0');

        $this->assertSame('Roast', $row['items'][0]['food_name']);
        $this->assertSame(2, $row['items'][0]['quantity']);
        $this->assertSame($order->reference(), $row['reference']);
        $this->assertTrue($this->at('17:00:00')->equalTo(CarbonImmutable::parse($row['needed_at'])));
    }

    public function test_an_empty_store_is_reported_as_empty(): void
    {
        $this->pending()
            ->assertJsonPath('summary', ['now' => 0, 'later' => 0, 'earlier' => 0, 'preparing' => 0])
            ->assertJsonPath('lead_minutes', 20);
    }
}
