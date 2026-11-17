<?php

namespace App\Services\Orders;

use App\Models\Order;

/**
 * When a customer may cancel, and what they get back (decided 2026-11-17).
 *
 *   immediate  only while `placed`, before a Store Agent confirms it; paid
 *              before it is placed, so refunded in full (BR-19, BR-20)
 *   advance    only while unpaid (`submitted`, `accepted`, `awaiting_payment`);
 *              nothing was charged, so nothing is refunded. Once paid, the
 *              customer cannot cancel it (BR-21)
 *
 * There is no configurable refund threshold any more. A cancellation the shop
 * makes is not decided here: the Store Agent chooses refund or no refund
 * (BR-21a, OrderQueueController::cancel).
 */
class CancellationPolicy
{
    public const ALREADY_CLOSED = 'ORDER_ALREADY_CLOSED';

    public const NOTHING_PAID = 'NOTHING_PAID';

    public const AWAITING_PAYMENT = 'AWAITING_PAYMENT';

    /** An immediate order the store has confirmed. */
    public const STORE_CONFIRMED = 'STORE_CONFIRMED';

    /** An advance order that has been paid for. */
    public const ADVANCE_PAID = 'ADVANCE_PAID';

    /** @return array<string, mixed> */
    public function for(Order $order): array
    {
        $status = (string) $order->status;

        if (OrderStatus::isTerminal($status)) {
            return $this->refused(self::ALREADY_CLOSED, 'This order is already '.strtolower(OrderStatus::label($status)).'.');
        }

        if ($order->isAdvance()) {
            return $this->forAdvance($order, $status);
        }

        // Not cancellable while being paid for: it ends by itself 10 minutes
        // after checkout if unpaid (FR-04.5).
        if ($status === OrderStatus::AWAITING_PAYMENT) {
            return $this->refused(
                self::AWAITING_PAYMENT,
                'This order is waiting for payment. If it is not paid within 10 minutes of checkout it is cancelled by itself, and nothing is charged.'
            );
        }

        if ($status !== OrderStatus::PLACED) {
            return $this->refused(
                self::STORE_CONFIRMED,
                'The store has already confirmed this order, so it can no longer be cancelled. Please call the store if something is wrong.'
            );
        }

        // Paid before it was placed, so the whole amount goes back. An order
        // from before payment existed may be unpaid; it then has nothing to refund.
        return $order->isPaid()
            ? $this->allowed(true, $order, null, 'You can cancel this order while the store has not confirmed it yet. Your payment will be refunded in full.')
            : $this->allowed(false, $order, self::NOTHING_PAID, 'You can cancel this order. You have not paid for it, so there is nothing to refund.');
    }

    public function canCancel(Order $order): bool
    {
        return $this->for($order)['can_cancel'];
    }

    public function outcomeMessage(array $decision): string
    {
        return $decision['refundable']
            ? 'Your order has been cancelled and your payment will be refunded in full.'
            : 'Your order has been cancelled. You had not paid for it, so there is nothing to refund.';
    }

    /** @return array<string, mixed> */
    private function forAdvance(Order $order, string $status): array
    {
        if ($order->isPaid() || ! in_array($status, AdvanceExpiry::UNPAID_STATUSES, true)) {
            return $this->refused(
                self::ADVANCE_PAID,
                'This advance order is paid, so it can no longer be cancelled. Please call the store if something is wrong.'
            );
        }

        return $this->allowed(
            false,
            $order,
            self::NOTHING_PAID,
            $status === OrderStatus::SUBMITTED
                ? 'You can withdraw this request. Nothing has been charged.'
                : 'You can cancel this order until you pay for it. Nothing has been charged, so there is nothing to refund.'
        );
    }

    /** @return array<string, mixed> */
    private function allowed(bool $refundable, Order $order, ?string $code, string $message): array
    {
        return [
            'can_cancel' => true,
            'refundable' => $refundable,
            'refund_amount' => $refundable ? round((float) ($order->total_amount ?? $order->total_price), 2) : 0.0,
            'code' => $code,
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function refused(string $code, string $message): array
    {
        return [
            'can_cancel' => false,
            'refundable' => false,
            'refund_amount' => 0.0,
            'code' => $code,
            'message' => $message,
        ];
    }
}
