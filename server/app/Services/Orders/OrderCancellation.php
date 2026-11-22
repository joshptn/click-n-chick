<?php

namespace App\Services\Orders;

use App\Mail\GuestOrderNotice;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\PaymentCheckout;
use App\Services\Payments\PaymentRefunds;
use Illuminate\Support\Facades\DB;

class OrderCancellation
{
    public function __construct(
        private CancellationPolicy $policy,
        private OrderAnnouncer $announcer,
        private OrderNotice $notice,
        private AdvanceExpiry $expiry,
        private PaymentCheckout $checkout,
        private PaymentRefunds $refunds,
    ) {}

    /**
     * @param  User|null  $by  Null when the customer has no account, which is what
     *                         leaves cancelled_by empty and so reads as the
     *                         customer's own doing rather than the shop's.
     * @return array the policy decision, whether or not it allowed the cancellation
     */
    public function cancel(Order $order, ?User $by): array
    {
        // An expired request is closed as expired, not as the customer's own
        // cancellation, so reports and the customer's notice say what happened.
        if ($refusal = $this->expiry->enforce($order)) {
            return [
                'can_cancel' => false,
                'refundable' => false,
                'refund_amount' => 0.0,
                'code' => $refusal['error_code'],
                'message' => $refusal['message'],
                'cancel_from' => null,
            ];
        }

        // Decided 2026-11-23: an unpaid advance order's open payment page is
        // closed first, so it cannot take money for an order about to go. One
        // paid at that very moment is now paid, and the policy refuses.
        if ($this->checkout->closeOpen($order)) {
            $order->refresh();
        }

        $decision = $this->policy->for($order);

        if (! $decision['can_cancel']) {
            return $decision;
        }

        DB::transaction(function () use ($order, $by, $decision) {
            $order->forceFill([
                'status' => OrderStatus::CANCELLED,
                'cancelled_by' => $by?->getKey(),
            ])->save();

            // Paid before it was placed, so the whole amount goes back (BR-20).
            if ($decision['refundable'] && $order->paidPayment !== null) {
                $this->refunds->owe($order->paidPayment, (float) $decision['refund_amount'], PaymentRefunds::REASON_CUSTOMER);
            }
        });

        $this->announcer->announce($order, 'cancelled', OrderStatus::CANCELLED);
        $this->announcer->toGuest($order, GuestOrderNotice::CANCELLED);

        // A booked advance order frees a slot somebody planned around, so the
        // staff are told. An immediate cancellation is already on the queue screen.
        if ($order->isAdvance()) {
            $this->announcer->toStaff($order, $this->notice->staffCancelled($order));
        }

        return $decision;
    }
}
