<?php

namespace App\Jobs;

use App\Mail\GuestOrderNotice;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\GuestTrackingLink;
use App\Services\Orders\OrderNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Email a guest about a cancellation or a refund (decided 2026-11-23).
 *
 * Only ids are queued: the worker words the message as things stand when it
 * runs and rebuilds the tracking link, so no working link sits in the jobs
 * table. Tried more than once: the guest has no other way to hear.
 */
class SendGuestOrderNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public int $orderId,
        public string $kind,
        public ?int $paymentId = null,
    ) {}

    public function handle(OrderNotice $notice, GuestTrackingLink $links): void
    {
        $order = Order::find($this->orderId);

        if ($order === null || ! $order->isGuest() || blank($order->guest_email) || ! in_array($this->kind, GuestOrderNotice::KINDS, true)) {
            return;
        }

        $payment = $this->paymentId === null ? null : Payment::query()->whereKey($this->paymentId)->where('order_id', $order->getKey())->first();
        $message = $notice->guestEmail($order, $this->kind, $payment);

        if ($message === null) {
            return;
        }

        $token = $order->guestToken();

        Mail::to($order->guest_email)->send(new GuestOrderNotice($order, $message, $token === null ? null : $links->url($token)));
    }
}
