<?php

namespace App\Services\Orders;

use App\Events\OrderBroadcast;
use App\Models\Order;
use App\Utils\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderAnnouncer
{
    /**
     * @param  string  $broadcastEvent  the wire event name the client switches on
     * @param  string|null  $notifyEvent  the lifecycle status to word the customer's
     *                                    notification from, or null to store none
     */
    public function announce(Order $order, string $broadcastEvent, ?string $notifyEvent = null): void
    {
        if ($notifyEvent !== null && $order->user_id !== null) {
            $this->attempt($order, 'notification', fn () => Notification::notify(
                'order', $notifyEvent, $order->user_id, $order
            ));
        }

        $this->attempt($order, $broadcastEvent, fn () => OrderBroadcast::dispatch(
            $order->load('items.food', 'items.food.category', 'user'), $broadcastEvent
        ));
    }

    /** A status change: the wire event and the customer's wording are the same fact. */
    public function statusChanged(Order $order): void
    {
        $this->announce($order, 'update', (string) $order->status);
    }

    private function attempt(Order $order, string $what, callable $action): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            Log::warning('An order changed but part of its announcement failed.', [
                'order_id' => $order->id,
                'part' => $what,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
