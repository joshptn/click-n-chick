<?php

namespace App\Services\Orders;

use App\Events\OrderBroadcast;
use App\Models\Order;
use App\Models\User;
use App\Utils\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderAnnouncer
{
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

    public function statusChanged(Order $order): void
    {
        $this->announce($order, 'update', (string) $order->status);
    }

    public function toStaff(Order $order, array $notice, array $roles = [User::ROLE_ADMIN]): int
    {
        $recipients = User::query()
            ->whereIn('role', $roles)
            ->where('account_status', User::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($recipients as $id) {
            $this->attempt(
                $order,
                'staff notification',
                fn () => Notification::send((int) $id, $notice, $order)
            );
        }

        return $recipients->count();
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
