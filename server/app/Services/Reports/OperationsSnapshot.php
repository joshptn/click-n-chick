<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Analytics Dashboard's cards: today's orders, read from the live rows.
 *
 * "Today" is the store's calendar day. New orders is every order placed today;
 * the delivery and pickup cards lead with what is still to be handed over. The
 * live counts follow today's queue, matching All Orders; an advance order joins
 * them in the kitchen on the day it is collected. Nothing from an earlier day
 * is counted.
 */
class OperationsSnapshot
{
    public function __construct(private StoreAvailability $store) {}

    /** @return array<string, mixed> */
    public function now(): array
    {
        $now = CarbonImmutable::now($this->store->timezone());
        $live = $this->liveOrders($now);
        $completed = $this->completedToday($now);
        $openDeliveries = $live->where('order_type', StoreAvailability::TYPE_DELIVERY)->count();

        return [
            'date' => $now->toDateString(),
            'timezone' => $now->timezone->getName(),
            'generated_at' => now()->toIso8601String(),

            'new_orders' => $this->placedToday($now),
            'preparing' => [
                'count' => $live->where('status', OrderStatus::PREPARING)->count(),
                // Confirmed, but the kitchen has not started it.
                'pending' => $live->where('status', OrderStatus::CONFIRMED)->count(),
            ],
            'delivery' => [
                'open' => $openDeliveries,
                'delivered' => $completed['delivery'],
            ],
            'pickup' => [
                'open' => $live->count() - $openDeliveries,
                'completed' => $completed['pickup'],
            ],
            'advance' => $this->advanceOrders($now),
        ];
    }

    /**
     * Today's advance orders, in the groups the Advance Orders page lists.
     *
     * An unpaid request lapses 24 hours after it is accepted (BR-16f), so what
     * is still to pay was accepted today or has its deadline today. Only a
     * payment record counts as paid.
     */
    private function advanceOrders(CarbonImmutable $now): array
    {
        $isToday = fn (?CarbonInterface $at) => $at !== null && $at->toImmutable()->setTimezone($now->timezone)->isSameDay($now);
        [$start, $end] = $this->day($now);

        $open = Order::query()
            ->whereNotNull('scheduled_for')
            ->whereNotIn('status', OrderStatus::terminal())
            ->get(['id', 'status', 'scheduled_for']);

        $collectsToday = $open->filter(fn (Order $order) => $isToday($order->scheduled_for));

        return [
            // Stays counted once the kitchen starts it, until it is collected.
            'due_today' => $collectsToday->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::SCHEDULED, OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP])->count(),
            'preparing' => $collectsToday->where('status', OrderStatus::PREPARING)->count(),
            'completed' => Order::query()
                ->whereNotNull('scheduled_for')
                ->where('status', OrderStatus::COMPLETED)
                ->whereRaw(Order::CLOSED_AT_SQL.' >= ?', [$start])
                ->whereRaw(Order::CLOSED_AT_SQL.' < ?', [$end])
                ->count(),
            'to_pay' => $open->whereIn('status', [OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT])->count(),
            'accepted' => Order::query()
                ->whereNotNull('scheduled_for')
                ->where('accepted_at', '>=', $start)
                ->where('accepted_at', '<', $end)
                ->count(),
            // Distinct: a retried payment must not count an order twice.
            'paid' => Payment::query()
                ->verified()
                ->where('payments.paid_at', '>=', $start)
                ->where('payments.paid_at', '<', $end)
                ->whereHas('order', fn (Builder $order) => $order->whereNotNull('scheduled_for'))
                ->distinct()
                ->count('payments.order_id'),
        ];
    }

    private function liveOrders(CarbonImmutable $now)
    {
        [$start, $end] = $this->day($now);

        return Order::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $regular) => $regular
                    ->whereNull('scheduled_for')
                    ->where('queue_date', $now->toDateString())
                    ->whereIn('status', [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP, OrderStatus::ON_THE_WAY]))
                ->orWhere(fn (Builder $advance) => $advance
                    ->where('scheduled_for', '>=', $start)
                    ->where('scheduled_for', '<', $end)
                    ->whereIn('status', [OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP])))
            ->toBase()
            ->get(['status', 'order_type']);
    }

    /** @return array{pickup: int, delivery: int} Orders handed over today, whenever they were placed. */
    private function completedToday(CarbonImmutable $now): array
    {
        [$start, $end] = $this->day($now);

        $rows = Order::query()
            ->whereIn('status', OrderStatus::fulfilled())
            ->whereRaw(Order::CLOSED_AT_SQL.' >= ?', [$start])
            ->whereRaw(Order::CLOSED_AT_SQL.' < ?', [$end])
            ->toBase()
            ->selectRaw("CASE WHEN order_type = ? THEN 'delivery' ELSE 'pickup' END AS type", [StoreAvailability::TYPE_DELIVERY])
            ->selectRaw('COUNT(*) AS orders')
            ->groupBy('type')
            ->pluck('orders', 'type');

        return ['pickup' => (int) ($rows['pickup'] ?? 0), 'delivery' => (int) ($rows['delivery'] ?? 0)];
    }

    /** @return array{today: int, completed: int, cancelled: int} Every order placed today, and how those have ended so far. */
    private function placedToday(CarbonImmutable $now): array
    {
        [$start, $end] = $this->day($now);

        $row = Order::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->toBase()
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS completed', OrderStatus::fulfilled())
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled', [OrderStatus::CANCELLED])
            ->first();

        return ['today' => (int) $row->orders, 'completed' => (int) $row->completed, 'cancelled' => (int) $row->cancelled];
    }

    /** @return array{0: string, 1: string} Today as a [start, end) window, in UTC as stored. */
    private function day(CarbonImmutable $now): array
    {
        return [
            $now->startOfDay()->utc()->format('Y-m-d H:i:s'),
            $now->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
