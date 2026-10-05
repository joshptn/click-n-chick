<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;

class OrderCancellation
{
    public function __construct(
        private CancellationPolicy $policy,
        private OrderAnnouncer $announcer,
        private OrderNotice $notice,
    ) {}

    /**
     * @param  User|null  $by  Null when the customer has no account, which is what
     *                         leaves cancelled_by empty and so reads as the
     *                         customer's own doing rather than the shop's.
     * @return array the policy decision, whether or not it allowed the cancellation
     */
    public function cancel(Order $order, ?User $by): array
    {
        $decision = $this->policy->for($order);

        if (! $decision['can_cancel']) {
            return $decision;
        }

        $order->forceFill([
            'status' => OrderStatus::CANCELLED,
            'cancelled_by' => $by?->getKey(),
            'refund_owed' => $decision['refund_amount'],
        ])->save();

        $this->announcer->announce($order, 'cancelled', OrderStatus::CANCELLED);

        // A booked advance order frees a slot somebody planned around, so the
        // staff are told. An immediate cancellation is already on the queue screen.
        if ($order->isAdvance()) {
            $this->announcer->toStaff($order, $this->notice->staffCancelled($order));
        }

        return $decision;
    }
}
