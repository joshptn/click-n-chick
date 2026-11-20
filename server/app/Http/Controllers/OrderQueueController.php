<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderQueueResource;
use App\Models\Order;
use App\Services\Orders\AdvanceExpiry;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrderQueueController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'items.addons', 'user', 'payments'];

    /** The board shows the day's latest finishes; the summary still counts them all. */
    private const COMPLETED_SHOWN = 50;

    public function __construct(
        private StoreAvailability $store,
        private OrderAnnouncer $announcer,
        private AdvanceExpiry $expiry,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $date = $this->date($request);

        $line = $this->onDay($date, OrderStatus::inLine());
        $handover = $this->onDay($date, [OrderStatus::READY_FOR_PICKUP, OrderStatus::ON_THE_WAY]);

        $completed = $this->dayQuery($date)
            ->whereIn('status', [OrderStatus::COMPLETED, OrderStatus::DELIVERED])
            ->with(self::WITH)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit(self::COMPLETED_SHOWN)
            ->get();

        // Trimmed 2026-11-20 to what the board reads: the store's state comes
        // from its own endpoint, and the counts per column from the lists.
        return response()->json([
            'date' => $date,

            'line' => OrderQueueResource::collection($line->values()),
            'handover' => OrderQueueResource::collection($handover),
            'completed' => OrderQueueResource::collection($completed),

            'summary' => [
                'completed_today' => $this->countOnDay($date, [OrderStatus::COMPLETED, OrderStatus::DELIVERED]),
                'cancelled_today' => $this->countOnDay($date, [OrderStatus::CANCELLED]),
            ],
        ]);
    }

    /**
     * One order as Today's Orders shows it, and whether it belongs there now
     * (decided 2026-11-20): a board updates the one card that changed instead
     * of reloading the whole day.
     */
    public function show(Order $order)
    {
        $this->authorize('viewAny', Order::class);

        $today = CarbonImmutable::now($this->store->timezone())->toDateString();

        return response()->json([
            'date' => $today,
            'on_board' => $this->dayQuery($today)->whereKey($order->getKey())->exists(),
            'order' => new OrderQueueResource($order->load(self::WITH)),
        ]);
    }

    /**
     * Pending Orders: what the next shift inherits.
     *
     * Confirmed orders the kitchen has not started, sorted by when each is
     * needed, and what it is working on. Advance orders due today are here too,
     * which the day's board never lists (BR-22c). An order is due "now" once
     * its time is within the kitchen's lead time.
     *
     * Unsettled Orders are immediate orders from an earlier day that never
     * closed, in any open status, kept here until someone resolves them (BR-47).
     * Advance orders stay on the Advance Orders page, whatever their date.
     */
    public function pending()
    {
        $this->authorize('viewAny', Order::class);

        $now = $this->store->now();
        $lead = (int) config('store.pickup.lead_minutes', 20);

        $groups = Order::query()
            ->with(self::WITH)
            ->where(fn (Builder $query) => $this->pendingScope($query, $now))
            ->get()
            ->sortBy(fn (Order $order) => ($this->neededAt($order) ?? $order->created_at)?->getTimestamp())
            ->groupBy(fn (Order $order) => $this->pendingGroup($order, $now));

        $rows = fn (string $group) => $groups->get($group, collect())
            ->map(fn (Order $order) => $this->pendingRow($order))
            ->values();

        return response()->json([
            'date' => $now->toDateString(),
            'lead_minutes' => $lead,
            'summary' => [
                'now' => $groups->get('now', collect())->count(),
                'later' => $groups->get('later', collect())->count(),
                'unsettled' => $groups->get('unsettled', collect())->count(),
                'preparing' => $groups->get('preparing', collect())->count(),
            ],
            'groups' => ['now' => $rows('now'), 'later' => $rows('later'), 'preparing' => $rows('preparing'), 'unsettled' => $rows('unsettled')],
        ]);
    }

    /**
     * One order as Pending Orders shows it: its row and group, or nulls when it
     * no longer belongs on the page (decided 2026-11-20).
     */
    public function pendingShow(Order $order)
    {
        $this->authorize('viewAny', Order::class);

        $now = $this->store->now();
        $listed = Order::query()
            ->with(self::WITH)
            ->whereKey($order->getKey())
            ->where(fn (Builder $query) => $this->pendingScope($query, $now))
            ->first();

        return response()->json([
            'id' => $order->getKey(),
            'group' => $listed ? $this->pendingGroup($listed, $now) : null,
            'row' => $listed ? $this->pendingRow($listed) : null,
        ]);
    }

    /**
     * Today's confirmed and cooking immediate orders, earlier days' open ones
     * (Unsettled), and advance orders due today.
     */
    private function pendingScope(Builder $query, CarbonImmutable $now): Builder
    {
        $dayStart = $now->startOfDay()->utc()->format('Y-m-d H:i:s');
        $dayEnd = $now->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s');

        return $query
            ->where(fn (Builder $regular) => $regular->whereNull('scheduled_for')->whereNotNull('order_number')->createdOnStoreDay($now)->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::PREPARING]))
            ->orWhere(fn (Builder $regular) => $regular->whereNull('scheduled_for')->whereNotNull('order_number')->createdBeforeStoreDay($now)->whereNotIn('status', OrderStatus::terminal()))
            ->orWhere(fn (Builder $advance) => $advance->where('scheduled_for', '>=', $dayStart)->where('scheduled_for', '<', $dayEnd)->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::SCHEDULED, OrderStatus::PREPARING]));
    }

    private function pendingGroup(Order $order, CarbonImmutable $now): string
    {
        $soon = $now->addMinutes((int) config('store.pickup.lead_minutes', 20));

        return match (true) {
            $this->isUnsettled($order, $now) => 'unsettled',
            $order->status === OrderStatus::PREPARING => 'preparing',
            $this->neededAt($order)?->greaterThan($soon) === true => 'later',
            default => 'now',
        };
    }

    private function pendingRow(Order $order): array
    {
        return (new OrderQueueResource($order))->resolve() + [
            'is_advance' => $order->isAdvance(),
            'needed_at' => $this->neededAt($order)?->toIso8601String(),
        ];
    }

    /** When the customer expects it. A delivery has no set time. */
    private function neededAt(Order $order): ?CarbonImmutable
    {
        return ($order->isAdvance() ? $order->scheduled_for : $order->pickup_at)?->toImmutable();
    }

    /** An immediate order created on an earlier day that is still open. */
    private function isUnsettled(Order $order, CarbonImmutable $now): bool
    {
        return ! $order->isAdvance() && $order->created_at->lessThan(Order::storeDay($now)[0]);
    }

    public function advance(Request $request, Order $order)
    {
        $this->authorize('update', $order);

        if ($refusal = $this->expiry->enforce($order)) {
            return response()->json($refusal, 422);
        }

        $to = $order->nextStatus();

        if ($to === null) {
            return response()->json([
                'message' => $order->isTerminal()
                    ? 'This order is already '.strtolower(OrderStatus::label((string) $order->status)).'.'
                    : 'This order cannot be advanced from '.strtolower(OrderStatus::label((string) $order->status)).'.',
                'error_code' => 'NOTHING_TO_ADVANCE',
                'status' => $order->status,
            ], 422);
        }

        if ($to === OrderStatus::DELIVERED) {
            $this->authorize('confirmReceipt', $order);
        }

        if ($to === OrderStatus::PREPARING && $order->isAdvance() && ! $order->isPaid()) {
            return response()->json([
                'message' => 'This order has not been paid for yet, so it cannot be prepared.',
                'error_code' => 'ADVANCE_UNPAID',
                'status' => $order->status,
            ], 422);
        }

        if ($order->isAdvance() && $order->status === OrderStatus::SUBMITTED) {
            return response()->json([
                'message' => 'Accept or reject this request from the advance list.',
                'error_code' => 'ADVANCE_NEEDS_DECISION',
                'status' => $order->status,
            ], 422);
        }

        // BRD 3.2: an accepted request leaves awaiting_payment only when the
        // customer pays. Stepping it to confirmed by hand would book an order
        // nobody has paid for.
        if ($order->isAdvance() && in_array((string) $order->status, [OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT], true)) {
            return response()->json([
                'message' => 'This request moves on when the customer pays for it.',
                'error_code' => 'ADVANCE_AWAITING_PAYMENT',
                'status' => $order->status,
            ], 422);
        }

        // Decided 2026-11-20: once its collection day has passed, a paid
        // advance order is neither scheduled nor prepared; it can only be
        // cancelled, with the refund choice.
        if ($order->isAdvance()
            && in_array($to, [OrderStatus::SCHEDULED, OrderStatus::PREPARING], true)
            && ($order->collectionDaysAway() ?? 0) < 0) {
            $collect = $order->scheduled_for->toImmutable()->setTimezone($this->store->timezone());

            return response()->json([
                'message' => 'This order was for '.$collect->format('j M').' and its collection day has passed. Cancel it instead.',
                'error_code' => 'ADVANCE_DATE_PASSED',
                'status' => $order->status,
            ], 422);
        }

        // UC-ADV-012: a scheduled order is prepared on its collection date.
        if ($to === OrderStatus::PREPARING && $order->isAdvance() && $this->collectsAfterToday($order)) {
            $collect = $order->scheduled_for->toImmutable()->setTimezone($this->store->timezone());

            return response()->json([
                'message' => 'This order is for '.$collect->format('j M').'. It is prepared on its collection date.',
                'error_code' => 'ADVANCE_NOT_DUE',
                'status' => $order->status,
            ], 422);
        }

        $order->status = $to;
        $order->save();

        $this->announcer->statusChanged($order);

        return response()->json([
            'message' => 'Order moved to '.strtolower(OrderStatus::label($to)).'.',
            'order' => new OrderQueueResource($order->load(self::WITH)),
        ]);
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorize('cancel', $order);

        // BR-21a (decided 2026-11-17): the reason is the customer's only
        // explanation, so it is required; for a paid order the agent must choose
        // refund or no refund outright - nothing is assumed.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'refund' => ['sometimes', 'boolean'],
        ], [
            'reason.required' => 'Give the customer a reason for the cancellation.',
        ]);

        if ($refusal = $this->expiry->enforce($order)) {
            return response()->json($refusal, 422);
        }

        if ($order->isTerminal()) {
            return response()->json([
                'message' => 'This order is already '.strtolower(OrderStatus::label((string) $order->status)).'.',
                'error_code' => 'ORDER_ALREADY_CLOSED',
                'status' => $order->status,
            ], 422);
        }

        $reason = trim((string) $validated['reason']);

        if ($reason === '') {
            return response()->json([
                'message' => 'Give the customer a reason for the cancellation.',
                'error_code' => 'REASON_REQUIRED',
            ], 422);
        }

        if ($order->isPaid() && ! array_key_exists('refund', $validated)) {
            return response()->json([
                'message' => 'This order is paid. Choose whether to refund it in full or not at all.',
                'error_code' => 'REFUND_CHOICE_REQUIRED',
            ], 422);
        }

        /**
         * BR-21a: a full refund or none, as the agent chose. Clamped to what was
         * actually paid: an unpaid order (an advance request the shop turns
         * down, usually) has nothing to give back, whatever was chosen.
         */
        $refund = ($validated['refund'] ?? false) && $order->isPaid()
            ? round((float) ($order->total_amount ?? $order->total_price), 2)
            : 0.0;

        $order->forceFill([
            'status' => OrderStatus::CANCELLED,
            'cancellation_reason' => $reason,
            'cancelled_by' => $request->user()->getKey(),
            'refund_owed' => $refund,
        ])->save();

        $this->announcer->statusChanged($order);

        return response()->json([
            'message' => match (true) {
                $refund > 0 => 'Order cancelled and marked for refund.',
                ! $order->isPaid() => 'Order cancelled. It had not been paid for, so there is nothing to refund.',
                default => 'Order cancelled without a refund.',
            },
            'refund_owed' => $refund,
            'order' => new OrderQueueResource($order->load(self::WITH)),
        ]);
    }

    private function collectsAfterToday(Order $order): bool
    {
        if ($order->scheduled_for === null) {
            return false;
        }

        $timezone = $this->store->timezone();

        // A copy: the cast is mutable, and moving its timezone in place would
        // make the column dirty for the save that follows.
        return $order->scheduled_for->toImmutable()->setTimezone($timezone)->startOfDay()
            ->greaterThan(CarbonImmutable::now($timezone)->startOfDay());
    }

    private function date(Request $request): string
    {
        $requested = $request->query('date');

        if (is_string($requested) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)) {
            return $requested;
        }

        return CarbonImmutable::now($this->store->timezone())->toDateString();
    }

    /**
     * The day's immediate orders (decided 2026-11-20): those created that store
     * day that were paid, which is when they get their number. Advance orders
     * never appear here.
     */
    private function dayQuery(string $date): Builder
    {
        return Order::query()
            ->whereNull('scheduled_for')
            ->whereNotNull('order_number')
            ->createdOnStoreDay(CarbonImmutable::parse($date, $this->store->timezone()));
    }

    /** @param  array<int, string>  $statuses */
    private function onDay(string $date, array $statuses): Collection
    {
        return $this->dayQuery($date)
            ->whereIn('status', $statuses)
            ->with(self::WITH)
            ->orderByRaw('CAST(order_number AS INTEGER)')
            ->orderBy('id')
            ->get();
    }

    /** @param  array<int, string>  $statuses */
    private function countOnDay(string $date, array $statuses): int
    {
        return $this->dayQuery($date)
            ->whereIn('status', $statuses)
            ->count();
    }
}
