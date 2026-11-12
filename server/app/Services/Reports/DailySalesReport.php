<?php

namespace App\Services\Reports;

use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Orders\AdvanceExpiry;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One store day's sales (UC-REP-002), computed from the order and payment rows.
 *
 * Two questions are kept apart on purpose, because they have different answers
 * until payment is live and different dates even after it is:
 *
 *   What was handed over?  Orders that ended completed or delivered, filed under
 *                          the day they closed, at their checkout snapshot.
 *   What money moved?      Only `payments` rows that record it, filed under the
 *                          moment it was paid or refunded.
 *
 * `orders.payment_status` alone never counts as money received: nothing but a
 * payment record proves it.
 */
class DailySalesReport
{
    private const TOP_ITEMS = 5;

    private const FOOD_SALES = 'COALESCE(orders.subtotal, '.Order::TOTAL_SQL.' + COALESCE(orders.discount_amount, 0) - COALESCE(orders.delivery_fee, 0))';

    public function __construct(private StoreAvailability $store) {}

    /**
     * @param  string  $date  A store calendar day, Y-m-d.
     * @param  array{order_type?: string, kind?: string}  $filters
     * @return array<string, mixed>
     */
    public function for(string $date, array $filters = []): array
    {
        $timezone = $this->store->timezone();
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $window = [
            $start->utc()->format('Y-m-d H:i:s'),
            $start->addDay()->utc()->format('Y-m-d H:i:s'),
        ];

        $fulfilled = $this->closedOn($window, $filters)->whereIn('status', OrderStatus::fulfilled());
        $cancelled = $this->closedOn($window, $filters)->where('status', OrderStatus::CANCELLED);
        $rejected = $this->closedOn($window, $filters)->where('status', OrderStatus::REJECTED);
        $open = $this->scoped(Order::query(), $filters)
            ->whereNotIn('status', OrderStatus::terminal())
            ->where('created_at', '>=', $window[0])
            ->where('created_at', '<', $window[1]);

        $sales = $this->totals($fulfilled);
        $notSold = $this->notSold($cancelled, $rejected);
        $openOrders = $this->countAndValue($open, 'value');

        return [
            'date' => $date,
            'timezone' => $timezone,
            'is_today' => $date === CarbonImmutable::now($timezone)->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'order_type' => $filters['order_type'] ?? 'all',
                'kind' => $filters['kind'] ?? 'all',
            ],

            // False until the first payment is ever recorded, so a page can say
            // "not available" rather than show ₱0.00 as though nothing was earned.
            'payments' => ['recording' => Payment::query()->verified()->exists()],

            'fulfilled' => $sales + [
                'average_order' => $sales['orders'] > 0 ? round($sales['total'] / $sales['orders'], 2) : 0.0,
                'discounted_orders' => (clone $fulfilled)->where('discount_amount', '>', 0)->count(),
                'mismatched_orders' => $this->mismatched($fulfilled),
            ],
            'verified_sales' => $this->totals($this->verified(clone $fulfilled)),
            'unverified_sales' => $this->unverified($fulfilled),

            'money' => $this->money($window, $filters, $cancelled),
            'refunds' => $this->refunds($cancelled),
            'not_sold' => $notSold,
            'open' => $openOrders,

            'breakdown' => [
                'pickup' => $this->totals((clone $fulfilled)->where(
                    fn (Builder $query) => $query->whereNull('order_type')->orWhere('order_type', '!=', StoreAvailability::TYPE_DELIVERY)
                )),
                'delivery' => $this->totals((clone $fulfilled)->where('order_type', StoreAvailability::TYPE_DELIVERY)),
                'regular' => $this->totals((clone $fulfilled)->whereNull('scheduled_for')),
                'advance' => $this->totals((clone $fulfilled)->whereNotNull('scheduled_for')),
            ],

            'top_items' => $this->topItems($fulfilled),

            'has_activity' => $sales['orders'] + $notSold['cancelled']['orders'] + $notSold['rejected']['orders'] + $openOrders['orders'] > 0,
        ];
    }

    /**
     * One light row per fulfilled order, for putting sales on a timeline in the
     * store's own hours. Grouped by the caller rather than in SQL, which has no
     * timezone conversion that works on both PostgreSQL and SQLite.
     *
     * @return Collection<int, object{closed: string, amount: mixed}>
     */
    public function fulfilledRows(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->closedBetween($start, $end)
            ->toBase()
            ->selectRaw(Order::CLOSED_AT_SQL.' AS closed')
            ->selectRaw(Order::TOTAL_SQL.' AS amount')
            ->get();
    }

    /** The best sellers by quantity between two moments, ranked as on the daily report. */
    public function topProducts(CarbonImmutable $start, CarbonImmutable $end, int $limit): array
    {
        return $this->topItems($this->closedBetween($start, $end), $limit);
    }

    private function closedBetween(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $this->closedOn([
            $start->utc()->format('Y-m-d H:i:s'),
            $end->utc()->format('Y-m-d H:i:s'),
        ], [])->whereIn('status', OrderStatus::fulfilled());
    }

    /** The same day and filters Order Records applies, so the two always agree. */
    private function closedOn(array $window, array $filters): Builder
    {
        return $this->scoped(Order::query(), $filters)
            ->whereRaw(Order::CLOSED_AT_SQL.' >= ?', [$window[0]])
            ->whereRaw(Order::CLOSED_AT_SQL.' < ?', [$window[1]]);
    }

    private function scoped(Builder $query, array $filters): Builder
    {
        $type = $filters['order_type'] ?? 'all';
        $kind = $filters['kind'] ?? 'all';

        return $query
            ->when($type !== 'all', fn (Builder $query) => $query->where('order_type', $type))
            ->when($kind === 'advance', fn (Builder $query) => $query->whereNotNull('scheduled_for'))
            ->when($kind === 'regular', fn (Builder $query) => $query->whereNull('scheduled_for'));
    }

    /** EXISTS rather than a join: a retried payment must not count an order twice. */
    private function verified(Builder $orders): Builder
    {
        return $orders->whereHas('payments', fn (Builder $payment) => $payment->verified());
    }

    private function totals(Builder $orders): array
    {
        $row = (clone $orders)
            ->toBase()
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('SUM('.self::FOOD_SALES.') AS food_sales')
            ->selectRaw('SUM(COALESCE(orders.discount_amount, 0)) AS discounts')
            ->selectRaw('SUM(COALESCE(orders.delivery_fee, 0)) AS delivery_fees')
            ->selectRaw('SUM('.Order::TOTAL_SQL.') AS total')
            ->first();

        return [
            'orders' => (int) $row->orders,
            'food_sales' => $this->money2($row->food_sales),
            'discounts' => $this->money2($row->discounts),
            'delivery_fees' => $this->money2($row->delivery_fees),
            'total' => $this->money2($row->total),
        ];
    }

    private function countAndValue(Builder $orders, string $valueKey): array
    {
        $row = (clone $orders)
            ->toBase()
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('SUM('.Order::TOTAL_SQL.') AS value')
            ->first();

        return ['orders' => (int) $row->orders, $valueKey => $this->money2($row->value)];
    }

    /** Orders whose stored figures do not add up, so a total is never silently wrong. */
    private function mismatched(Builder $fulfilled): int
    {
        return (clone $fulfilled)
            ->whereRaw('ABS('.self::FOOD_SALES.' - COALESCE(orders.discount_amount, 0) + COALESCE(orders.delivery_fee, 0) - '.Order::TOTAL_SQL.') >= 0.01')
            ->count();
    }

    private function unverified(Builder $fulfilled): array
    {
        $orders = (clone $fulfilled)->whereDoesntHave('payments', fn (Builder $payment) => $payment->verified());

        return $this->totals($orders) + [
            // Marked paid on the order with no payment behind it: demo data today.
            'marked_paid_orders' => (clone $orders)->where('payment_status', Payment::STATUS_PAID)->count(),
        ];
    }

    private function money(array $window, array $filters, Builder $cancelled): array
    {
        $paidOnDay = fn (Builder $payment) => $payment->verified()
            ->where('payments.paid_at', '>=', $window[0])
            ->where('payments.paid_at', '<', $window[1]);

        $received = $this->countAndValue(
            $this->scoped(Order::query(), $filters)->whereHas('payments', $paidOnDay),
            'amount'
        );

        $refundRow = Payment::query()
            ->whereNotNull('payments.refunded_at')
            ->where('payments.refunded_at', '>=', $window[0])
            ->where('payments.refunded_at', '<', $window[1])
            ->whereHas('order', fn (Builder $order) => $this->scoped($order, $filters))
            ->toBase()
            ->selectRaw('COUNT(*) AS payments')
            ->selectRaw('SUM(COALESCE(payments.refund_amount, 0)) AS amount')
            ->first();

        $settled = ['payments' => (int) $refundRow->payments, 'amount' => $this->money2($refundRow->amount)];

        // A paid order cancelled once the kitchen had started: BR-20 keeps the
        // money, but the food was not sold, so it stays out of sales.
        $keptAmount = 'SUM('.Order::TOTAL_SQL.' - COALESCE(orders.refund_owed, 0))';
        $kept = $this->verified(clone $cancelled)
            ->whereRaw(Order::TOTAL_SQL.' - COALESCE(orders.refund_owed, 0) > 0')
            ->toBase()
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw($keptAmount.' AS amount')
            ->first();

        return [
            'received' => $received,
            'refunds_settled' => $settled,
            'net_received' => round($received['amount'] - $settled['amount'], 2),
            'kept_on_cancelled' => ['orders' => (int) $kept->orders, 'amount' => $this->money2($kept->amount)],
            'by_method' => $this->byMethod($window, $filters),
        ];
    }

    private function byMethod(array $window, array $filters): array
    {
        return Payment::query()
            ->verified()
            ->where('payments.paid_at', '>=', $window[0])
            ->where('payments.paid_at', '<', $window[1])
            // One row per order, for the same reason as verified().
            ->whereIn('payments.id', Payment::query()->verified()->selectRaw('MAX(payments.id)')->groupBy('payments.order_id'))
            ->whereHas('order', fn (Builder $order) => $this->scoped($order, $filters))
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->toBase()
            ->select('payments.payment_method')
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('SUM('.Order::TOTAL_SQL.') AS amount')
            ->groupBy('payments.payment_method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method,
                'orders' => (int) $row->orders,
                'amount' => $this->money2($row->amount),
            ])
            ->all();
    }

    private function refunds(Builder $cancelled): array
    {
        $owed = (clone $cancelled)->where('refund_owed', '>', 0);
        $sum = fn (Builder $orders) => [
            'orders' => (clone $orders)->count(),
            'amount' => $this->money2((clone $orders)->sum('refund_owed')),
        ];

        return [
            'owed' => $sum($owed),
            'awaiting' => $sum((clone $owed)->whereDoesntHave('payments', fn (Builder $payment) => $payment->whereNotNull('payments.refunded_at'))),
        ];
    }

    private function notSold(Builder $cancelled, Builder $rejected): array
    {
        $byStore = 'orders.cancelled_by IS NOT NULL AND (orders.user_id IS NULL OR orders.cancelled_by <> orders.user_id)';

        $row = (clone $cancelled)
            ->toBase()
            ->selectRaw("SUM(CASE WHEN {$byStore} THEN 1 ELSE 0 END) AS by_store")
            ->selectRaw('SUM(CASE WHEN orders.cancelled_by IS NULL AND orders.cancellation_reason IN (?, ?, ?) THEN 1 ELSE 0 END) AS expired_unpaid', AdvanceExpiry::REASONS)
            ->first();

        $counted = $this->countAndValue($cancelled, 'value');

        return [
            'cancelled' => $counted + [
                'by_store' => (int) $row->by_store,
                'expired_unpaid' => (int) $row->expired_unpaid,
                'by_customer' => $counted['orders'] - (int) $row->by_store - (int) $row->expired_unpaid,
            ],
            'rejected' => $this->countAndValue($rejected, 'value'),
        ];
    }

    private function topItems(Builder $fulfilled, int $limit = self::TOP_ITEMS): array
    {
        $rows = OrderItem::query()
            ->whereIn('order_id', (clone $fulfilled)->select('orders.id'))
            ->toBase()
            ->select('food_id')
            ->selectRaw('SUM(quantity) AS quantity')
            ->selectRaw('SUM(COALESCE(subtotal, price * quantity)) AS sales')
            ->groupBy('food_id')
            ->orderByDesc('quantity')
            ->orderByDesc('sales')
            ->limit($limit)
            ->get();

        $foods = Food::withTrashed()->with('category:id,name')->whereIn('id', $rows->pluck('food_id'))->get(['id', 'food_name', 'category_id'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'food_id' => (int) $row->food_id,
            'food_name' => $foods[$row->food_id]->food_name ?? 'Removed item',
            'category' => $foods[$row->food_id]->category->name ?? null,
            'quantity' => (int) $row->quantity,
            'sales' => $this->money2($row->sales),
        ])->all();
    }

    private function money2(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
