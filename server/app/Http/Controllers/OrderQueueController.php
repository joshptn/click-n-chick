<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderQueueResource;
use App\Models\Order;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrderQueueController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'user'];

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

        return response()->json([
            'date' => $date,
            'store' => $this->store->snapshot(),

            'line' => $line->values()->map(
                fn (Order $order, int $index) => (new OrderQueueResource($order))->withPosition($index + 1)
            ),
            'handover' => OrderQueueResource::collection($handover),

            'summary' => [
                'in_line' => $line->count(),
                'awaiting_handover' => $handover->count(),
                'awaiting_confirmation' => $line->where('status', OrderStatus::PLACED)->count(),
                'completed_today' => $this->countOnDay($date, [OrderStatus::COMPLETED, OrderStatus::DELIVERED]),
                'cancelled_today' => $this->countOnDay($date, [OrderStatus::CANCELLED]),

                'longest_wait_minutes' => $line->first()?->queued_at?->diffInMinutes(now()),
            ],
        ]);
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

        $refund = $validated['refund'] ?? true
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
            'message' => $refund > 0
                ? 'Order cancelled and marked for refund.'
                : 'Order cancelled without a refund.',
            'refund_owed' => $refund,
            'order' => new OrderQueueResource($order->load(self::WITH)),
        ]);
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
