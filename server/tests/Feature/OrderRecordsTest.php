<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order Records (UC-OPS-007): closed orders, read-only, for both staff roles.
 */
class OrderRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = User::ROLE_ADMIN): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function food(string $name = 'Herb Roasted Chicken', float $price = 299): Food
    {
        return Food::create([
            'thumbnail' => 'https://example.test/chicken.png',
            'food_name' => $name,
            'price' => $price,
            'description' => 'Half a chicken.',
            'is_available' => true,
        ]);
    }

    private function order(array $attributes = [], ?Food $food = null, int $quantity = 1): Order
    {
        $order = Order::create($attributes + [
            'user_id' => User::factory()->create(['first_name' => 'Julia', 'last_name' => 'Veneese'])->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 299 * $quantity,
            'discount_amount' => 0,
            'delivery_fee' => 0,
            'total_price' => 299 * $quantity,
            'total_amount' => 299 * $quantity,
            'payment_status' => 'unpaid',
        ]);

        $food ??= $this->food();

        OrderItem::create([
            'order_id' => $order->id,
            'food_id' => $food->id,
            'quantity' => $quantity,
            'price' => $food->price,
            'unit_price' => $food->price,
            'subtotal' => $food->price * $quantity,
        ]);

        return $order;
    }

    /** Close an order the way the app does, which stamps closed_at. */
    private function close(Order $order, string $status, ?CarbonImmutable $at = null): Order
    {
        if ($at) {
            $this->travelTo($at);
        }

        $order->forceFill(['status' => $status])->save();
        $this->travelBack();

        return $order->fresh();
    }

    public function test_only_closed_orders_are_records(): void
    {
        $done = $this->close($this->order(), OrderStatus::COMPLETED);
        $delivered = $this->close($this->order(['order_type' => 'delivery']), OrderStatus::DELIVERED);
        $cancelled = $this->close($this->order(), OrderStatus::CANCELLED);
        $this->order(); // still placed
        $this->close($this->order(), OrderStatus::PREPARING);

        $ids = collect(
            $this->actingAs($this->staff(), 'sanctum')->getJson('/api/agent/records')->assertOk()->json('data')
        )->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$done->id, $delivered->id, $cancelled->id])->sort()->values()->all(), $ids);
    }

    public function test_the_store_manager_may_read_records_and_a_customer_may_not(): void
    {
        $order = $this->close($this->order(), OrderStatus::COMPLETED);

        $this->actingAs($this->staff(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson('/api/agent/records')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer, 'sanctum')->getJson('/api/agent/records')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->getJson('/api/agent/records/'.$order->id)->assertForbidden();
    }

    public function test_records_are_newest_closed_first_and_paginated(): void
    {
        $older = $this->close($this->order(), OrderStatus::COMPLETED, CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Manila'));
        $newer = $this->close($this->order(), OrderStatus::COMPLETED, CarbonImmutable::parse('2026-11-03 10:00', 'Asia/Manila'));
        $middle = $this->close($this->order(), OrderStatus::CANCELLED, CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Manila'));

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/agent/records?per_page=2&page=2')
            ->assertJsonPath('data.0.id', $older->id);

        $this->getJson('/api/agent/records?sort=oldest')
            ->assertJsonPath('data.0.id', $older->id);
    }

    public function test_the_page_size_has_a_ceiling(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_dates_are_the_stores_calendar_days_of_closing(): void
    {
        // 23:30 in Manila on the 2nd is 15:30 UTC: still the 2nd for the store.
        $late = $this->close($this->order(), OrderStatus::COMPLETED, CarbonImmutable::parse('2026-11-02 23:30', 'Asia/Manila'));
        // 00:30 in Manila on the 3rd is 16:30 UTC on the 2nd: the 3rd for the store.
        $early = $this->close($this->order(), OrderStatus::COMPLETED, CarbonImmutable::parse('2026-11-03 00:30', 'Asia/Manila'));

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records?from=2026-11-02&to=2026-11-02')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $late->id);

        $this->getJson('/api/agent/records?from=2026-11-03')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $early->id);

        $this->getJson('/api/agent/records?from=2026-11-03&to=2026-11-02')
            ->assertJsonValidationErrors('to');
    }

    public function test_the_outcome_filter_and_the_summary_count_within_the_other_filters(): void
    {
        $this->close($this->order(), OrderStatus::COMPLETED);
        $this->close($this->order(['order_type' => 'delivery', 'delivery_fee' => 50, 'total_amount' => 349, 'total_price' => 349]), OrderStatus::DELIVERED);
        $this->close($this->order(['discount_amount' => 59.8, 'total_amount' => 239.2, 'total_price' => 239.2]), OrderStatus::COMPLETED);
        $cancelled = $this->order(['payment_status' => 'paid']);
        $cancelled->forceFill(['status' => OrderStatus::CANCELLED, 'refund_owed' => 299, 'cancelled_by' => $this->staff()->id])->save();
        $this->close($this->order(['scheduled_for' => now()->addDays(3)]), OrderStatus::REJECTED);

        $response = $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records?outcome=completed')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $response->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.completed', 3)
            ->assertJsonPath('summary.cancelled', 1)
            ->assertJsonPath('summary.rejected', 1);

        $this->assertEqualsWithDelta(299 + 349 + 239.2, $response->json('summary.completed_value'), 0.001);
        $this->assertEqualsWithDelta(59.8, $response->json('summary.discounts_given'), 0.001);
        $this->assertEqualsWithDelta(299, $response->json('summary.refunds_owed'), 0.001);

        // The summary narrows with the other filters.
        $this->getJson('/api/agent/records?order_type=delivery')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.completed', 1);

        $this->getJson('/api/agent/records?kind=advance')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outcome', 'rejected')
            ->assertJsonPath('data.0.is_advance', true);

        $this->getJson('/api/agent/records?payment=refund_owed')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cancelled->id)
            ->assertJsonPath('data.0.cancelled_by_store', true);
    }

    public function test_search_finds_a_ticket_number_an_id_a_name_a_guest_and_a_dish(): void
    {
        $julia = $this->order();
        $julia->enterQueue();
        $julia = $this->close($julia, OrderStatus::COMPLETED);

        $guest = $this->close($this->order([
            'user_id' => null,
            'guest_name' => 'Veneese Lazaraga',
            'guest_phone' => '09171234567',
            'guest_email' => 'lazaraga@example.test',
        ], $this->food('Liempo', 250)), OrderStatus::COMPLETED);

        $staff = $this->staff();
        $find = fn (string $term) => collect(
            $this->actingAs($staff, 'sanctum')->getJson('/api/agent/records?search='.urlencode($term))->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertContains($julia->id, $find($julia->queueLabel()));
        $this->assertSame([$guest->id], $find('#'.$guest->id));
        $this->assertSame([$julia->id], $find('julia ven'));
        $this->assertSame([$guest->id], $find('lazaraga'));
        $this->assertSame([$guest->id], $find('1234567'));
        $this->assertSame([$guest->id], $find('liempo'));
        $this->assertSame([], $find('nobody at all'));
    }

    public function test_an_account_phone_number_matches_whole(): void
    {
        $customer = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $customer->forceFill([
            'phone_number' => '+639171112222',
            'phone_number_hash' => User::hashPhoneNumber('+639171112222'),
        ])->save();

        $order = $this->close($this->order(['user_id' => $customer->id]), OrderStatus::COMPLETED);
        $this->close($this->order(), OrderStatus::COMPLETED);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records?search=09171112222')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id);
    }

    public function test_a_record_shows_the_prices_it_was_sold_at(): void
    {
        $food = $this->food('Herb Roasted Chicken', 299);
        $order = $this->order([
            'order_type' => 'delivery',
            'subtotal' => 628,
            'discount_amount' => 0,
            'delivery_fee' => 45,
            'total_amount' => 673,
            'total_price' => 673,
            'payment_status' => 'paid',
            'full_address' => '12 Mabini St',
        ], $food, 2);

        $item = $order->items()->first();
        $item->forceFill(['unit_price' => 314, 'subtotal' => 628])->save();
        $addon = Addon::create(['addon_name' => 'Extra Gravy', 'addon_price' => 20]);
        $item->addons()->attach($addon->id, ['unit_price' => 15]);
        Payment::create(['order_id' => $order->id, 'payment_method' => 'gcash', 'payment_status' => 'paid', 'paid_at' => now()]);

        // Today's menu price has moved on; the record must not.
        $food->update(['price' => 350]);
        $order = $this->close($order, OrderStatus::DELIVERED);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 314)
            ->assertJsonPath('data.items.0.subtotal', 628)
            ->assertJsonPath('data.items.0.addons.0.addon_name', 'Extra Gravy')
            ->assertJsonPath('data.items.0.addons.0.unit_price', 15)
            ->assertJsonPath('data.subtotal', 628)
            ->assertJsonPath('data.delivery_fee', 45)
            ->assertJsonPath('data.total', 673)
            ->assertJsonPath('data.payment.method', 'gcash')
            ->assertJsonPath('data.delivery.full_address', '12 Mabini St')
            ->assertJsonPath('data.item_count', 2);
    }

    public function test_a_store_cancellation_names_the_staff_member_and_a_customers_does_not(): void
    {
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN, 'first_name' => 'Rhea', 'last_name' => 'Santos']);
        $byStore = $this->order();
        $byStore->forceFill(['status' => OrderStatus::CANCELLED, 'cancelled_by' => $agent->id, 'cancellation_reason' => 'Out of chicken.'])->save();

        $byCustomer = $this->order();
        $byCustomer->forceFill(['status' => OrderStatus::CANCELLED, 'cancelled_by' => $byCustomer->user_id])->save();

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records/'.$byStore->id)
            ->assertJsonPath('data.cancelled_by_store', true)
            ->assertJsonPath('data.cancelled_by_name', 'Rhea Santos')
            ->assertJsonPath('data.cancellation_reason', 'Out of chicken.');

        $this->getJson('/api/agent/records/'.$byCustomer->id)
            ->assertJsonPath('data.cancelled_by_store', false)
            ->assertJsonPath('data.cancelled_by_name', null);
    }

    public function test_an_open_order_is_not_a_record(): void
    {
        $open = $this->order();

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/agent/records/'.$open->id)
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ORDER_NOT_CLOSED');
    }

    public function test_reading_records_changes_nothing(): void
    {
        $order = $this->close($this->order(), OrderStatus::COMPLETED);
        $before = $order->fresh()->getAttributes();

        $this->actingAs($this->staff(), 'sanctum')->getJson('/api/agent/records')->assertOk();
        $this->getJson('/api/agent/records/'.$order->id)->assertOk();

        $this->assertSame($before, $order->fresh()->getAttributes());
    }
}
