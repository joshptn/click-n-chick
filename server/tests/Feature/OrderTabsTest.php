<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My Orders' tabs (decided 2026-11-26): Today and Advance keep an order until
 * its day has passed whatever became of it; Past holds completed and delivered
 * orders after that; Cancelled holds every cancelled, declined or rejected
 * order at once.
 */
class OrderTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 on Wednesday 2 December in Manila.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-02 10:00', 'Asia/Manila')->utc());
        $this->travelTo(CarbonImmutable::now());

        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** A paid order placed at `$placed` (Manila), immediate or for `$collect`. */
    private function order(string $status, string $placed, ?string $collect = null): Order
    {
        $order = $this->orderWith([
            'user_id' => $this->customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 200,
            'delivery_fee' => 0,
            'discount_amount' => 0,
            'total_amount' => 200,
            'total_price' => 200,
            'scheduled_for' => $collect ? CarbonImmutable::parse($collect, 'Asia/Manila')->utc() : null,
            'payment_status' => 'paid',
        ]);

        $order->forceFill([
            'status' => $status,
            'created_at' => CarbonImmutable::parse($placed, 'Asia/Manila')->utc(),
        ])->save();

        return $order;
    }

    private function tab(string $filter): array
    {
        return collect($this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/orders?filter={$filter}")
            ->assertOk()
            ->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_today_keeps_todays_orders_whatever_became_of_them(): void
    {
        $preparing = $this->order(OrderStatus::PREPARING, '2026-12-02 09:00');
        $completed = $this->order(OrderStatus::COMPLETED, '2026-12-02 08:00');
        $cancelled = $this->order(OrderStatus::CANCELLED, '2026-12-02 08:30');
        // Placed late last night, still with the store.
        $stillOpen = $this->order(OrderStatus::READY_FOR_PICKUP, '2026-12-01 23:40');
        $this->order(OrderStatus::COMPLETED, '2026-12-01 12:00');
        $this->order(OrderStatus::SCHEDULED, '2026-12-01 12:00', '2026-12-05 12:00');

        $this->assertSame(collect([$preparing, $completed, $cancelled, $stillOpen])->pluck('id')->sort()->values()->all(), $this->tab('today'));
    }

    public function test_today_follows_the_store_date_not_utc(): void
    {
        // 07:30 in Manila is still yesterday in UTC.
        $early = $this->order(OrderStatus::COMPLETED, '2026-12-02 07:30');

        $this->assertSame([$early->id], $this->tab('today'));
        $this->assertSame([], $this->tab('past'));
    }

    public function test_advance_keeps_orders_until_their_collection_day_has_passed(): void
    {
        $request = $this->order(OrderStatus::SUBMITTED, '2026-11-30 10:00', '2026-12-04 12:00');
        $declined = $this->order(OrderStatus::REJECTED, '2026-11-30 10:00', '2026-12-06 12:00');
        $collectedToday = $this->order(OrderStatus::COMPLETED, '2026-11-25 10:00', '2026-12-02 09:00');
        $this->order(OrderStatus::COMPLETED, '2026-11-20 10:00', '2026-12-01 12:00');
        $this->order(OrderStatus::PREPARING, '2026-12-02 09:00');

        $this->assertSame(collect([$request, $declined, $collectedToday])->pluck('id')->sort()->values()->all(), $this->tab('advance'));
    }

    public function test_advance_lists_the_soonest_collection_first(): void
    {
        $later = $this->order(OrderStatus::SCHEDULED, '2026-11-30 10:00', '2026-12-09 12:00');
        $sooner = $this->order(OrderStatus::SCHEDULED, '2026-12-01 10:00', '2026-12-03 12:00');

        $ids = collect($this->actingAs($this->customer, 'sanctum')->getJson('/api/orders?filter=advance')->json('data'))->pluck('id')->all();

        $this->assertSame([$sooner->id, $later->id], $ids);
    }

    public function test_past_holds_completed_and_delivered_orders_once_their_day_has_passed(): void
    {
        $yesterday = $this->order(OrderStatus::COMPLETED, '2026-12-01 12:00');
        $delivered = $this->order(OrderStatus::DELIVERED, '2026-11-28 12:00');
        $collected = $this->order(OrderStatus::COMPLETED, '2026-11-20 10:00', '2026-12-01 12:00');
        $this->order(OrderStatus::COMPLETED, '2026-12-02 08:00');
        $this->order(OrderStatus::CANCELLED, '2026-11-28 12:00');

        $this->assertSame(collect([$yesterday, $delivered, $collected])->pluck('id')->sort()->values()->all(), $this->tab('past'));
    }

    public function test_cancelled_holds_every_negative_outcome_at_once(): void
    {
        $today = $this->order(OrderStatus::CANCELLED, '2026-12-02 08:30');
        $declined = $this->order(OrderStatus::REJECTED, '2026-11-30 10:00', '2026-12-06 12:00');
        $old = $this->order(OrderStatus::CANCELLED, '2026-11-15 12:00');
        $this->order(OrderStatus::COMPLETED, '2026-11-15 12:00');

        $this->assertSame(collect([$today, $declined, $old])->pluck('id')->sort()->values()->all(), $this->tab('cancelled'));

        // The one cancelled today shows in Today as well, until the day ends.
        $this->assertContains($today->id, $this->tab('today'));
    }

    public function test_the_counts_name_today_and_advance(): void
    {
        $this->order(OrderStatus::PREPARING, '2026-12-02 09:00');
        $this->order(OrderStatus::SUBMITTED, '2026-12-01 10:00', '2026-12-04 12:00');
        $this->order(OrderStatus::SCHEDULED, '2026-12-01 10:00', '2026-12-05 12:00');

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/orders?filter=past')
            ->assertOk()
            ->assertJsonPath('counts.today', 1)
            ->assertJsonPath('counts.advance', 2)
            ->assertJsonPath('counts.active', 3);
    }
}
