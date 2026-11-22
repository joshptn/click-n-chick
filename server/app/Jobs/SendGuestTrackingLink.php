<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Orders\GuestTrackingLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Email a guest their tracking link once the payment is confirmed
 * (FR-02.13a, decided 2026-11-22).
 *
 * Dispatched by PaymentSettlement, which lets only the first confirmation of
 * a payment through, so the webhook and the guest's own check cannot both
 * send it. Only the order's id is queued: the worker rebuilds the token, so
 * no working link ever sits in the jobs table.
 *
 * Tried more than once, unlike the new-device alert: a Gmail hiccup is worth
 * another go, and the guest may have no other copy of the link.
 */
class SendGuestTrackingLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $orderId) {}

    public function handle(GuestTrackingLink $links): void
    {
        $order = Order::find($this->orderId);

        if ($order === null || ! $order->isGuest() || blank($order->guest_email)) {
            return;
        }

        $links->sendFirst($order);
    }
}
