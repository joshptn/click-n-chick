<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderQueueResource;
use App\Models\Order;
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
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $date = $this->date($request);

        $line = $this->onDay($date, OrderStatus::inLine());
        $handover = $this->onDay($date, [OrderStatus::READY_FOR_PICKUP, OrderStatus::ON_THE_WAY]);

        $completed = Order::query()
            ->where('queue_date', $date)
            ->whereIn('status', [OrderStatus::COMPLETED, OrderStatus::DELIVERED])
            ->with(self::WITH)
            ->orderByDesc('closed_at')
            ->orderByDesc('queue_number')
            ->limit(self::COMPLETED_SHOWN)
            ->get();

        return response()->json([
            'date' => $date,
            'store' => $this->store->snapshot(),

            'line' => $line->values()->map(
                fn (Order $order, int $index) => (new OrderQueueResource($order))->withPosition($index + 1)
            ),
            'handover' => OrderQueueResource::collection($handover),
            'completed' => OrderQueueResource::collection($completed),

            'summary' => [
                'in_line' => $line->count(),
                'awaiting_handover' => $handover->count(),
                'awaiting_confirmation' => $line->where('status', OrderStatus::PLACED)->count(),
                'completed_today' => $this->countOnDay($date, [OrderStatus::COMPLETED, OrderStatus::DELIVERED]),
                'cancelled_today' => $this->countOnDay($date, [OrderStatus::CANCELLED]),

                'longest_wait_minutes' => $line->first()?->queued_at
                    ? (int) $line->first()->queued_at->diffInMinutes(now())
                    : null,
            ],
        ]);
    }

    /**
     * Pending Orders: what the next shift inherits.
     *
     * Confirmed orders the kitchen has not started, sorted by when each is
     * needed, and what it is working on. Advance orders due today are here too,
     * which the day's board never lists (BR-22c), and so is anything left open
     * from an earlier day. An order is due "now" once its time is within the
     * kitchen's lead time.
     */
    public function pending()
    {
        $this->authorize('viewAny', Order::class);

        $now = $this->store->now();
        $today = $now->toDateString();
        $lead = (int) config('store.pickup.lead_minutes', 20);
        $soon = $now->addMinutes($lead);
        $dayStart = $now->startOfDay()->utc()->format('Y-m-d H:i:s');
        $dayEnd = $now->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s');

        $booked = [OrderStatus::CONFIRMED, OrderStatus::SCHEDULED];
        $inKitchen = [OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP];

        $orders = Order::query()
            ->with(self::WITH)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $regular) => $regular->whereNull('scheduled_for')->where('queue_date', $today)->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::PREPARING]))
                ->orWhere(fn (Builder $regular) => $regular->whereNull('scheduled_for')->where('queue_date', '<', $today)->whereNotIn('status', OrderStatus::terminal()))
                ->orWhere(fn (Builder $advance) => $advance->where('scheduled_for', '>=', $dayStart)->where('scheduled_for', '<', $dayEnd)->whereIn('status', [...$booked, OrderStatus::PREPARING]))
                ->orWhere(fn (Builder $advance) => $advance->where('scheduled_for', '<', $dayStart)->whereIn('status', [...$booked, ...$inKitchen])))
            ->get();

        $groups = $orders
            ->sortBy(fn (Order $order) => ($this->neededAt($order) ?? $order->queued_at ?? $order->created_at)?->getTimestamp())
            ->groupBy(fn (Order $order) => match (true) {
                $this->isFromEarlierDay($order, $today, $dayStart) => 'earlier',
                $order->status === OrderStatus::PREPARING => 'preparing',
                $this->neededAt($order)?->greaterThan($soon) === true => 'later',
                default => 'now',
            });

        $rows = fn (string $group) => $groups->get($group, collect())
            ->map(fn (Order $order) => (new OrderQueueResource($order))->resolve() + [
                'reference' => $order->reference(),
                'is_advance' => $order->isAdvance(),
                'needed_at' => $this->neededAt($order)?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'date' => $today,
            'lead_minutes' => $lead,
            'summary' => [
                'now' => $groups->get('now', collect())->count(),
                'later' => $groups->get('later', collect())->count(),
                'earlier' => $groups->get('earlier', collect())->count(),
                'preparing' => $groups->get('preparing', collect())->count(),
            ],
            'groups' => ['now' => $rows('now'), 'later' => $rows('later'), 'preparing' => $rows('preparing'), 'earlier' => $rows('earlier')],
        ]);
    }

    /** When the customer expects it. A delivery has no set time. */
    private function neededAt(Order $order): ?CarbonImmutable
    {
        return ($order->isAdvance() ? $order->scheduled_for : $order->pickup_at)?->toImmutable();
    }

    private function isFromEarlierDay(Order $order, string $today, string $dayStart): bool
    {
        return $order->isAdvance()
            ? $order->scheduled_for->format('Y-m-d H:i:s') < $dayStart
            : CarbonImmutable::parse($order->queue_date)->toDateString() < $today;
    }

    public function next(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $date = $this->date($request);
        $order = $this->onDay($date, OrderStatus::inLine())->first();

        return response()->json([
            'date' => $date,
            'order' => $order ? (new OrderQueueResource($order))->withPosition(1) : null,
        ]);
    }

    public function advance(Request $request, Order $order)
    {
        $this->authorize('update', $order);

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

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'refund' => ['sometimes', 'boolean'],
        ]);

        if ($order->isTerminal()) {
            return response()->json([
                'message' => 'This order is already '.strtolower(OrderStatus::label((string) $order->status)).'.',
                'error_code' => 'ORDER_ALREADY_CLOSED',
                'status' => $order->status,
            ], 422);
        }

        $reason = trim((string) ($validated['reason'] ?? ''));

        /**
         * BR-21a: a shop-initiated cancellation refunds regardless of the
         * window, because that is not the customer's fault. The agent may waive
         * it for a cancellation the customer phoned in.
         *
         * Clamped to what was actually paid either way. An advance request the
         * shop turns down has usually never been paid, and recording a refund
         * against a charge that never happened would hand the payment provider
         * something it cannot settle.
         */
        $refund = ($validated['refund'] ?? true) && $order->isPaid()
            ? round((float) ($order->total_amount ?? $order->total_price), 2)
            : 0.0;

        $order->forceFill([
            'status' => OrderStatus::CANCELLED,
            'cancellation_reason' => $reason === '' ? null : $reason,
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

    /** @param  array<int, string>  $statuses */
    private function onDay(string $date, array $statuses): Collection
    {
        return Order::query()
            ->where('queue_date', $date)
            ->whereIn('status', $statuses)
            ->with(self::WITH)
            ->orderBy('queue_number')
            ->get();
    }

    /** @param  array<int, string>  $statuses */
    private function countOnDay(string $date, array $statuses): int
    {
        return Order::query()
            ->where('queue_date', $date)
            ->whereIn('status', $statuses)
            ->count();
    }
}
