<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderRecordResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Order Records (UC-OPS-007): closed orders, read-only.
 *
 * Only orders that have ended - completed, delivered, cancelled or rejected -
 * are records. Anything still open belongs to the board or the advance list,
 * where it can be acted on; nothing here changes an order.
 *
 * Dates are the store's calendar days, and an order is filed under the day it
 * closed. Rows from before `closed_at` was stamped fall back to their last
 * update, which is when they closed.
 */
class OrderRecordController extends Controller
{
    use AuthorizesRequests;

    public const PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /** How a record ended, as the page filters it. */
    public const OUTCOMES = [
        'completed' => [OrderStatus::COMPLETED, OrderStatus::DELIVERED],
        'cancelled' => [OrderStatus::CANCELLED],
        'rejected' => [OrderStatus::REJECTED],
    ];

    public const SORTS = ['newest', 'oldest', 'total_high', 'total_low'];

    private const CLOSED_AT = 'COALESCE(orders.closed_at, orders.updated_at)';

    private const TOTAL = 'COALESCE(orders.total_amount, orders.total_price, 0)';

    private const LIST_WITH = ['user:id,first_name,last_name', 'items.food:id,food_name'];

    private const DETAIL_WITH = [
        'user:id,first_name,last_name,email,phone_number',
        'items.food:id,food_name,thumbnail',
        'items.addons',
        'payments',
        'cancelledBy:id,first_name,last_name,role',
    ];

    public function __construct(private StoreAvailability $store) {}

    /** GET /api/agent/records */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'outcome' => ['sometimes', 'string', Rule::in(['all', ...array_keys(self::OUTCOMES)])],
            'order_type' => ['sometimes', 'string', Rule::in(['all', StoreAvailability::TYPE_PICKUP, StoreAvailability::TYPE_DELIVERY])],
            'kind' => ['sometimes', 'string', Rule::in(['all', 'regular', 'advance'])],
            'payment' => ['sometimes', 'string', Rule::in(['all', 'paid', 'unpaid', 'refund_owed'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $outcome = $validated['outcome'] ?? 'all';

        // Every filter but the outcome, so the summary can count each outcome
        // within what the agent is looking at.
        $base = $this->filtered($validated);

        $query = (clone $base)
            ->when($outcome !== 'all', fn (Builder $query) => $query->whereIn('status', self::OUTCOMES[$outcome]))
            ->with(self::LIST_WITH)
            ->withSum('items as item_count', 'quantity');

        $this->sort($query, $validated['sort'] ?? 'newest');

        $records = $query->paginate((int) ($validated['per_page'] ?? self::PER_PAGE));

        return response()->json([
            'data' => OrderRecordResource::collection($records->items()),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'from' => $records->firstItem(),
                'to' => $records->lastItem(),
                'timezone' => $this->store->timezone(),
            ],
            'summary' => $this->summary($base),
        ]);
    }

    /** GET /api/agent/records/{order} */
    public function show(Order $order)
    {
        $this->authorize('view', $order);

        if (! $order->isTerminal()) {
            return response()->json([
                'message' => 'This order is still open, so it is not a record yet. Find it on All Orders or Advance Order Requests.',
                'error_code' => 'ORDER_NOT_CLOSED',
                'status' => $order->status,
            ], 404);
        }

        $order->load(self::DETAIL_WITH)->loadSum('items as item_count', 'quantity');

        return response()->json([
            'data' => (new OrderRecordResource($order))->detailed(),
        ]);
    }

    private function filtered(array $filters): Builder
    {
        $timezone = $this->store->timezone();

        $query = Order::query()
            ->whereIn('status', OrderStatus::terminal())
            ->when(
                ($filters['order_type'] ?? 'all') !== 'all',
                fn (Builder $query) => $query->where('order_type', $filters['order_type'])
            )
            ->when(($filters['kind'] ?? 'all') === 'advance', fn (Builder $query) => $query->whereNotNull('scheduled_for'))
            ->when(($filters['kind'] ?? 'all') === 'regular', fn (Builder $query) => $query->whereNull('scheduled_for'))
            ->when(($filters['payment'] ?? 'all') === 'paid', fn (Builder $query) => $query->where('payment_status', 'paid'))
            ->when(($filters['payment'] ?? 'all') === 'unpaid', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid')
            ))
            ->when(($filters['payment'] ?? 'all') === 'refund_owed', fn (Builder $query) => $query->where('refund_owed', '>', 0))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereRaw(
                self::CLOSED_AT.' >= ?',
                [CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->format('Y-m-d H:i:s')]
            ))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereRaw(
                self::CLOSED_AT.' < ?',
                [CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s')]
            ));

        $this->search($query, (string) ($filters['search'] ?? ''));

        return $query;
    }

    /**
     * What an agent would type: the number on the ticket, the order's id, a
     * name, an email, a phone number, or a dish.
     *
     * Account phone numbers are encrypted at rest, so they match whole, through
     * the same hash registration uses; a guest's number is plain and matches
     * in part.
     */
    private function search(Builder $query, string $raw): void
    {
        // LIKE wildcards are dropped rather than escaped: SQLite has no default
        // escape character, and nobody searches for a literal % in a name.
        $term = trim(str_replace(['%', '_'], ' ', $raw));

        if ($term === '') {
            return;
        }

        $needle = '%'.mb_strtolower($term).'%';
        $digits = preg_replace('/\D/', '', $term);

        // "#12", "012", "BES-012": an order id or a ticket number.
        $number = preg_match('/^(?:[A-Za-z]+-)?#?(\d{1,9})$/', $term, $match) ? (int) $match[1] : null;
        $phoneHash = strlen($digits) >= 10 ? User::hashPhoneNumber($term) : null;

        $query->where(function (Builder $where) use ($needle, $number, $digits, $phoneHash) {
            if ($number !== null) {
                $where->orWhere('orders.id', $number)->orWhere('orders.queue_number', $number);
            }

            $where
                ->orWhereRaw('LOWER(orders.guest_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(orders.guest_email) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(orders.order_number) LIKE ?', [$needle])
                ->orWhereHas('user', fn (Builder $user) => $user
                    ->whereRaw("LOWER(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) LIKE ?", [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle]))
                ->orWhereHas('items.food', fn (Builder $food) => $food->whereRaw('LOWER(food_name) LIKE ?', [$needle]));

            if (strlen($digits) >= 7) {
                $where->orWhere('orders.guest_phone', 'like', '%'.$digits.'%');
            }

            if ($phoneHash !== null) {
                $where->orWhereHas('user', fn (Builder $user) => $user->where('phone_number_hash', $phoneHash));
            }
        });
    }

    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderByRaw(self::CLOSED_AT.' ASC')->orderBy('orders.id'),
            'total_high' => $query->orderByRaw(self::TOTAL.' DESC')->orderByDesc('orders.id'),
            'total_low' => $query->orderByRaw(self::TOTAL.' ASC')->orderByDesc('orders.id'),
            default => $query->orderByRaw(self::CLOSED_AT.' DESC')->orderByDesc('orders.id'),
        };
    }

    /**
     * Counts per outcome and the money behind them, within the filters.
     *
     * Totals are the order totals as recorded at checkout. Completed value is
     * what fulfilled orders came to; it is not settled revenue - payments are
     * recorded separately and refunds owed are listed on their own.
     */
    private function summary(Builder $base): array
    {
        $rows = (clone $base)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->selectRaw('SUM('.self::TOTAL.') AS value')
            ->selectRaw('SUM(COALESCE(orders.discount_amount, 0)) AS discounts')
            ->selectRaw('SUM(COALESCE(orders.refund_owed, 0)) AS refunds')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $sum = fn (array $statuses, string $column) => round(
            (float) collect($statuses)->sum(fn (string $status) => (float) ($rows[$status]->{$column} ?? 0)),
            2
        );

        return [
            'total' => (int) $rows->sum('total'),
            'completed' => (int) $sum(self::OUTCOMES['completed'], 'total'),
            'cancelled' => (int) $sum(self::OUTCOMES['cancelled'], 'total'),
            'rejected' => (int) $sum(self::OUTCOMES['rejected'], 'total'),
            'completed_value' => $sum(self::OUTCOMES['completed'], 'value'),
            'discounts_given' => $sum(self::OUTCOMES['completed'], 'discounts'),
            'refunds_owed' => $sum(self::OUTCOMES['cancelled'], 'refunds'),
        ];
    }
}
