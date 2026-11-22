<?php

namespace App\Services\Orders;

use App\Events\OrderBroadcast;
use App\Jobs\SendGuestOrderNotice;
use App\Models\Order;
use App\Models\Payment;
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

    /** A notice for the order's account holder, in the app and by push. Guests have no account. */
    public function toCustomer(Order $order, array $notice): void
    {
        if ($order->user_id === null) {
            return;
        }

        $this->attempt($order, 'customer notification', fn () => Notification::send((int) $order->user_id, $notice, $order));
    }

    /**
     * An email for the order's guest (decided 2026-11-23: guests are told by
     * email). Queued; the worker builds the message and the tracking link.
     */
    public function toGuest(Order $order, string $kind, ?Payment $payment = null): void
    {
        if (! $order->isGuest() || blank($order->guest_email)) {
            return;
        }

        $this->attempt($order, 'guest email', fn () => SendGuestOrderNotice::dispatch(
            (int) $order->getKey(),
            $kind,
            $payment?->getKey(),
        )->afterCommit());
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
