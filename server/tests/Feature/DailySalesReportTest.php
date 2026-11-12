<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\AdvanceExpiry;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Daily Sales Report (UC-REP-002): every figure from the rows, and nothing
 * called money received without a payment record behind it.
 */
class DailySalesReportTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-09';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::DAY.' 18:00:00', 'Asia/Manila'));
    }

    private function user(string $role = User::ROLE_CUSTOMER): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function report(array $query = []): TestResponse
    {
        return $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson('/api/admin/reports/daily-sales?'.http_build_query($query + ['date' => self::DAY]))
            ->assertOk();
    }

    private function order(array $attributes = [], string $status = OrderStatus::COMPLETED, ?string $closedAt = null): Order
    {
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

        if ($status !== OrderStatus::PLACED) {
            $this->travelTo($closedAt ? CarbonImmutable::parse($closedAt, 'Asia/Manila') : CarbonImmutable::parse(self::DAY.' 12:00:00', 'Asia/Manila'));
            $order->forceFill(['status' => $status])->save();
            $this->travelTo(CarbonImmutable::parse(self::DAY.' 18:00:00', 'Asia/Manila'));
        }

        return $order->fresh();
    }

    private function pay(Order $order, string $at = self::DAY.' 11:00:00', array $attributes = []): Payment
    {
        return Payment::create($attributes + [
            'order_id' => $order->id,
            'payment_method' => 'gcash',
            'payment_status' => Payment::STATUS_PAID,
            'paid_at' => CarbonImmutable::parse($at, 'Asia/Manila'),
        ]);
    }

    private function food(string $name, float $price = 100): Food
    {
        return Food::create([
            'thumbnail' => 'https://example.test/a.png',
            'food_name' => $name,
            'price' => $price,
            'description' => 'A dish.',
            'is_available' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_only_the_store_manager_may_read_the_report(): void
    {
        $url = '/api/admin/reports/daily-sales';

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($this->user(), 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')->getJson($url)->assertOk()->assertJsonPath('date', self::DAY);
    }

    public function test_a_bad_date_or_filter_is_refused(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        foreach (['date=2026-11-10', 'date=2026-02-30', 'date=yesterday', 'order_type=dine_in', 'kind=everything'] as $query) {
            $this->actingAs($manager, 'sanctum')
                ->getJson('/api/admin/reports/daily-sales?'.$query)
                ->assertUnprocessable();
        }
    }

    // -----------------------------------------------------------------
    // What was handed over
    // -----------------------------------------------------------------

    public function test_fulfilled_figures_add_up_from_the_checkout_snapshot(): void
    {
        $this->order(['subtotal' => 500, 'discount_amount' => 100, 'total_amount' => 400]);
        $this->order(['order_type' => 'delivery', 'subtotal' => 300, 'delivery_fee' => 65, 'total_amount' => 365], OrderStatus::DELIVERED);

        $this->report()
            ->assertJsonPath('fulfilled.orders', 2)
            ->assertJsonPath('fulfilled.food_sales', 800)
            ->assertJsonPath('fulfilled.discounts', 100)
            ->assertJsonPath('fulfilled.delivery_fees', 65)
            ->assertJsonPath('fulfilled.total', 765)
            ->assertJsonPath('fulfilled.average_order', 382.5)
            ->assertJsonPath('fulfilled.discounted_orders', 1)
            ->assertJsonPath('fulfilled.mismatched_orders', 0)
            ->assertJsonPath('breakdown.pickup.total', 400)
            ->assertJsonPath('breakdown.delivery.total', 365)
            ->assertJsonPath('breakdown.delivery.delivery_fees', 65);
    }

    public function test_cancelled_declined_and_open_orders_are_not_sales(): void
    {
        $customer = $this->user();

        $this->order(['total_amount' => 300]);
        $this->order(['total_amount' => 111, 'user_id' => $customer->id, 'cancelled_by' => $customer->id], OrderStatus::CANCELLED);
        $this->order(['total_amount' => 222, 'cancelled_by' => $this->user(User::ROLE_ADMIN)->id], OrderStatus::CANCELLED);
        $this->order([
            'total_amount' => 333,
            'scheduled_for' => now()->addDay(),
            'cancellation_reason' => 'Payment was not received within 24 hours of the store accepting this request.',
        ], OrderStatus::CANCELLED);
        $this->order(['total_amount' => 444, 'scheduled_for' => now()->addDay()], OrderStatus::REJECTED);
        $this->order(['total_amount' => 555], OrderStatus::PLACED);
        $this->order(['total_amount' => 666], OrderStatus::PREPARING);

        $this->report()
            ->assertJsonPath('fulfilled.orders', 1)
            ->assertJsonPath('fulfilled.total', 300)
            ->assertJsonPath('not_sold.cancelled.orders', 3)
            ->assertJsonPath('not_sold.cancelled.value', 666)
            ->assertJsonPath('not_sold.cancelled.by_customer', 1)
            ->assertJsonPath('not_sold.cancelled.by_store', 1)
            ->assertJsonPath('not_sold.cancelled.expired_unpaid', 1)
            ->assertJsonPath('not_sold.rejected.orders', 1)
            ->assertJsonPath('not_sold.rejected.value', 444)
            ->assertJsonPath('open.orders', 2)
            ->assertJsonPath('open.value', 1221);
    }

    public function test_every_kind_of_advance_expiry_counts_as_expired_unpaid(): void
    {
        foreach (AdvanceExpiry::REASONS as $reason) {
            $this->order(['scheduled_for' => now()->addDay(), 'cancellation_reason' => $reason], OrderStatus::CANCELLED);
        }

        // A customer who happens to write the same words is still the customer.
        $customer = $this->user();
        $this->order(['user_id' => $customer->id, 'cancelled_by' => $customer->id, 'cancellation_reason' => AdvanceExpiry::UNANSWERED], OrderStatus::CANCELLED);

        $this->report()
            ->assertJsonPath('not_sold.cancelled.expired_unpaid', 3)
            ->assertJsonPath('not_sold.cancelled.by_customer', 1);
    }

    public function test_an_order_is_filed_under_the_store_day_it_closed(): void
    {
        $this->order(['total_amount' => 100], OrderStatus::COMPLETED, '2026-11-08 23:59:00');
        $this->order(['total_amount' => 200], OrderStatus::COMPLETED, self::DAY.' 00:01:00');
        $this->order(['total_amount' => 400], OrderStatus::COMPLETED, self::DAY.' 17:59:00');

        $this->report()->assertJsonPath('fulfilled.total', 600);
        $this->report(['date' => '2026-11-08'])->assertJsonPath('fulfilled.total', 100)->assertJsonPath('is_today', false);
    }

    public function test_legacy_rows_and_inconsistent_rows_are_handled_openly(): void
    {
        // From before the snapshot columns: only total_price, so food is what is left.
        $legacy = $this->order(['total_amount' => 250]);
        $legacy->forceFill(['subtotal' => null, 'total_amount' => null, 'total_price' => 250])->save();

        // Stored figures that do not add up are counted, not repaired.
        $this->order(['subtotal' => 100, 'total_amount' => 900]);

        $this->report()
            ->assertJsonPath('fulfilled.food_sales', 350)
            ->assertJsonPath('fulfilled.total', 1150)
            ->assertJsonPath('fulfilled.mismatched_orders', 1);
    }

    // -----------------------------------------------------------------
    // What money moved
    // -----------------------------------------------------------------

    public function test_nothing_is_verified_without_a_payment_record(): void
    {
        $this->order(['total_amount' => 300]);
        $this->order(['total_amount' => 200, 'payment_status' => 'paid']);

        $this->report()
            ->assertJsonPath('payments.recording', false)
            ->assertJsonPath('verified_sales.orders', 0)
            ->assertJsonPath('verified_sales.total', 0)
            ->assertJsonPath('unverified_sales.orders', 2)
            ->assertJsonPath('unverified_sales.total', 500)
            ->assertJsonPath('unverified_sales.marked_paid_orders', 1)
            ->assertJsonPath('money.received.amount', 0);
    }

    public function test_a_pending_payment_record_is_not_money_received(): void
    {
        $order = $this->order(['total_amount' => 300]);
        Payment::create(['order_id' => $order->id, 'payment_status' => 'pending']);
        Payment::create(['order_id' => $order->id, 'payment_status' => Payment::STATUS_PAID, 'paid_at' => null]);

        $this->report()
            ->assertJsonPath('payments.recording', false)
            ->assertJsonPath('verified_sales.orders', 0);
    }

    public function test_verified_sales_split_from_the_fulfilled_total(): void
    {
        $this->pay($this->order(['subtotal' => 500, 'discount_amount' => 100, 'total_amount' => 400]));
        $this->order(['total_amount' => 300]);

        $this->report()
            ->assertJsonPath('payments.recording', true)
            ->assertJsonPath('fulfilled.total', 700)
            ->assertJsonPath('verified_sales.orders', 1)
            ->assertJsonPath('verified_sales.total', 400)
            ->assertJsonPath('verified_sales.discounts', 100)
            ->assertJsonPath('unverified_sales.total', 300)
            ->assertJsonPath('money.received.orders', 1)
            ->assertJsonPath('money.received.amount', 400);
    }

    public function test_a_retried_payment_does_not_count_an_order_twice(): void
    {
        $order = $this->order(['total_amount' => 300]);
        $this->pay($order);
        $this->pay($order, self::DAY.' 11:05:00');

        $this->report()
            ->assertJsonPath('verified_sales.total', 300)
            ->assertJsonPath('money.received.amount', 300)
            ->assertJsonCount(1, 'money.by_method')
            ->assertJsonPath('money.by_method.0.orders', 1)
            ->assertJsonPath('money.by_method.0.amount', 300);
    }

    public function test_money_is_dated_when_paid_and_the_sale_when_fulfilled(): void
    {
        // An advance order paid yesterday and collected today.
        $this->pay(
            $this->order(['total_amount' => 800, 'scheduled_for' => now()]),
            '2026-11-08 10:00:00'
        );

        $this->report()
            ->assertJsonPath('verified_sales.total', 800)
            ->assertJsonPath('breakdown.advance.total', 800)
            ->assertJsonPath('money.received.amount', 0);

        $this->report(['date' => '2026-11-08'])
            ->assertJsonPath('fulfilled.total', 0)
            ->assertJsonPath('money.received.amount', 800);
    }

    public function test_refunds_owed_are_kept_apart_from_refunds_settled(): void
    {
        $settled = $this->order(['total_amount' => 300, 'refund_owed' => 300], OrderStatus::CANCELLED);
        $this->pay($settled, self::DAY.' 09:00:00', [
            'refund_amount' => 300,
            'refund_status' => 'refunded',
            'refunded_at' => CarbonImmutable::parse(self::DAY.' 13:00:00', 'Asia/Manila'),
        ]);

        $this->pay($this->order(['total_amount' => 200, 'refund_owed' => 200], OrderStatus::CANCELLED), self::DAY.' 09:30:00');

        $this->report()
            ->assertJsonPath('fulfilled.total', 0)
            ->assertJsonPath('verified_sales.total', 0)
            ->assertJsonPath('refunds.owed.orders', 2)
            ->assertJsonPath('refunds.owed.amount', 500)
            ->assertJsonPath('refunds.awaiting.orders', 1)
            ->assertJsonPath('refunds.awaiting.amount', 200)
            ->assertJsonPath('money.received.amount', 500)
            ->assertJsonPath('money.refunds_settled.amount', 300)
            ->assertJsonPath('money.net_received', 200)
            ->assertJsonPath('money.kept_on_cancelled.orders', 0);
    }

    public function test_money_kept_on_a_late_cancellation_is_not_a_sale(): void
    {
        // BR-20: paid, cancelled after the kitchen started, nothing refunded.
        $this->pay($this->order(['total_amount' => 350, 'refund_owed' => 0], OrderStatus::CANCELLED));

        $this->report()
            ->assertJsonPath('verified_sales.total', 0)
            ->assertJsonPath('money.received.amount', 350)
            ->assertJsonPath('money.kept_on_cancelled.orders', 1)
            ->assertJsonPath('money.kept_on_cancelled.amount', 350);
    }

    // -----------------------------------------------------------------
    // Filters, items and agreement with Order Records
    // -----------------------------------------------------------------

    public function test_filters_narrow_every_section(): void
    {
        $this->pay($this->order(['order_type' => 'delivery', 'delivery_fee' => 50, 'subtotal' => 100, 'total_amount' => 150], OrderStatus::DELIVERED));
        $this->pay($this->order(['total_amount' => 200]));
        $this->order(['total_amount' => 70, 'scheduled_for' => now()], OrderStatus::CANCELLED);
        $this->order(['total_amount' => 90], OrderStatus::PLACED);

        $this->report(['order_type' => 'delivery'])
            ->assertJsonPath('fulfilled.total', 150)
            ->assertJsonPath('verified_sales.total', 150)
            ->assertJsonPath('money.received.amount', 150)
            ->assertJsonPath('not_sold.cancelled.orders', 0)
            ->assertJsonPath('open.orders', 0);

        $this->report(['kind' => 'advance'])
            ->assertJsonPath('fulfilled.orders', 0)
            ->assertJsonPath('not_sold.cancelled.orders', 1)
            ->assertJsonPath('open.orders', 0);
    }

    public function test_top_items_use_sold_prices_and_keep_deleted_dishes(): void
    {
        $inasal = $this->food('Chicken Inasal', 160);
        $gone = $this->food('Retired Dish', 90);

        $order = $this->order(['total_amount' => 500]);
        OrderItem::create(['order_id' => $order->id, 'food_id' => $inasal->id, 'quantity' => 3, 'price' => 100, 'unit_price' => 100, 'subtotal' => 300]);
        OrderItem::create(['order_id' => $order->id, 'food_id' => $gone->id, 'quantity' => 2, 'price' => 100, 'unit_price' => 100, 'subtotal' => 200]);

        $cancelled = $this->order(['total_amount' => 999], OrderStatus::CANCELLED);
        OrderItem::create(['order_id' => $cancelled->id, 'food_id' => $gone->id, 'quantity' => 9, 'price' => 111, 'unit_price' => 111, 'subtotal' => 999]);

        $inasal->update(['price' => 500]);
        $gone->delete();

        $this->report()
            ->assertJsonCount(2, 'top_items')
            ->assertJsonPath('top_items.0.food_name', 'Chicken Inasal')
            ->assertJsonPath('top_items.0.quantity', 3)
            ->assertJsonPath('top_items.0.sales', 300)
            ->assertJsonPath('top_items.1.food_name', 'Retired Dish')
            ->assertJsonPath('top_items.1.quantity', 2);
    }

    public function test_the_report_agrees_with_order_records_for_the_same_day(): void
    {
        $this->pay($this->order(['total_amount' => 400, 'discount_amount' => 40, 'subtotal' => 440]));
        $this->order(['order_type' => 'delivery', 'total_amount' => 150], OrderStatus::DELIVERED);
        $this->order(['total_amount' => 60], OrderStatus::CANCELLED);
        $this->order(['total_amount' => 999], OrderStatus::COMPLETED, '2026-11-08 12:00:00');

        $report = $this->report()->json();

        $records = $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson('/api/agent/records?from='.self::DAY.'&to='.self::DAY)
            ->assertOk()
            ->json();

        $this->assertEquals($report['fulfilled']['total'], $records['summary']['completed_value']);
        $this->assertEquals($report['fulfilled']['discounts'], $records['summary']['discounts_given']);
        $this->assertSame($report['fulfilled']['orders'], $records['summary']['completed']);
        $this->assertSame($report['not_sold']['cancelled']['orders'], $records['summary']['cancelled']);

        $verified = collect($records['data'])->where('payment_verified', true);
        $this->assertCount(1, $verified);
        $this->assertEquals($report['verified_sales']['total'], $verified->sum('total'));
    }

    public function test_an_empty_day_reports_zeroes_and_no_activity(): void
    {
        $this->report()
            ->assertJsonPath('has_activity', false)
            ->assertJsonPath('fulfilled.orders', 0)
            ->assertJsonPath('fulfilled.total', 0)
            ->assertJsonPath('fulfilled.average_order', 0)
            ->assertJsonPath('top_items', []);
    }

    public function test_reading_the_report_changes_nothing(): void
    {
        $order = $this->order(['total_amount' => 300]);
        $before = $order->fresh()->getAttributes();

        $this->report();

        $this->assertSame($before, $order->fresh()->getAttributes());
    }
}
