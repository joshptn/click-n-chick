<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Loyalty\LoyaltyPoints;
use App\Services\Store\StoreContact;
use Carbon\CarbonImmutable;

/**
 * When a customer may cancel, and what they get back (decided 2026-11-17).
 *
 *   immediate  only while `placed`, before a Store Agent confirms it, and only
 *              once the store has had 15 minutes from payment to answer
 *              (decided 2026-11-23); paid before it is placed, so refunded in
 *              full (BR-19, BR-20)
 *   advance    only while unpaid (`submitted`, `accepted`, `awaiting_payment`);
 *              nothing was charged, so nothing is refunded. Once paid, the
 *              customer cannot cancel it (BR-21)
 *
 * Points an order was paid with come back with any cancellation
 * (`points_back`, decided 2026-11-24); an order paid wholly with points has
 * no money to refund.
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

    /** A placed immediate order inside the store's 15 minutes to answer. */
    public const AWAITING_STORE = 'AWAITING_STORE';

    /** Paid wholly with points: cancellable as a paid one, with no money to refund. */
    public const PAID_WITH_POINTS = 'PAID_WITH_POINTS';

    /** Wording decided 2026-11-23, on the web and in the app. */
    public const CONFIRMED_MESSAGE = 'Your order is already confirmed and will be prepared any time now, so it can no longer be cancelled through the app. Please contact %s if you need assistance.';

    public function __construct(
        private StoreContact $contact,
        private LoyaltyPoints $points,
    ) {}

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
            return $this->refused(self::STORE_CONFIRMED, sprintf(self::CONFIRMED_MESSAGE, $this->contact->theStore()));
        }

        // An order from before payment existed may be unpaid; it then has
        // nothing to refund and nothing to wait for.
        if (! $order->isPaid()) {
            return $this->allowed(false, $order, self::NOTHING_PAID, 'You can cancel this order. You have not paid for it, so there is nothing to refund.');
        }

        $from = $this->cancelFrom($order);

        if ($from !== null && $from->isFuture()) {
            $at = $from->setTimezone((string) config('store.timezone', 'Asia/Manila'))->format('g:i A');

            return array_merge($this->refused(
                self::AWAITING_STORE,
                "The store has until {$at} to confirm your order. If it hasn't by then, you can cancel it here for a full refund."
            ), ['cancel_from' => $from->toIso8601String()]);
        }

        if (round((float) ($order->total_amount ?? $order->total_price), 2) <= 0) {
            return $this->allowed(false, $order, self::PAID_WITH_POINTS, 'You can cancel this order while the store has not confirmed it yet. You paid with points, so they come back to your balance.');
        }

        // Paid before it was placed, so the whole amount goes back.
        return $this->allowed(true, $order, null, 'You can cancel this order while the store has not confirmed it yet. Your payment will be refunded in full.'
            .($this->points->heldFor($order) > 0 ? ' The points you used come back to your balance.' : ''));
    }

    /** When a placed immediate order may first be cancelled: the store's minutes from payment, then. */
    public function cancelFrom(Order $order): ?CarbonImmutable
    {
        $paidAt = $order->paidPayment?->paid_at;

        return $paidAt === null
            ? null
            : CarbonImmutable::parse($paidAt)->addMinutes((int) config('store.cancel_wait_minutes', 15));
    }

    public function canCancel(Order $order): bool
    {
        return $this->for($order)['can_cancel'];
    }

    public function outcomeMessage(array $decision): string
    {
        $pointsBack = ($decision['points_back'] ?? 0) > 0 ? ' Your points are back in your balance.' : '';

        if (($decision['code'] ?? null) === self::PAID_WITH_POINTS) {
            return 'Your order has been cancelled.'.$pointsBack;
        }

        return ($decision['refundable']
            ? 'Your order has been cancelled and your payment will be refunded in full.'
            : 'Your order has been cancelled. You had not paid for it, so there is nothing to refund.').$pointsBack;
    }

    /** @return array<string, mixed> */
    private function forAdvance(Order $order, string $status): array
    {
        if ($order->isPaid() || ! in_array($status, AdvanceExpiry::UNPAID_STATUSES, true)) {
            return $this->refused(
                self::ADVANCE_PAID,
                'This advance order is paid, so it can no longer be cancelled. Please contact '.$this->contact->theStore().' if something is wrong.'
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
            // Points the order holds, given back when it is cancelled.
            'points_back' => $this->points->heldFor($order),
            'code' => $code,
            'message' => $message,
            'cancel_from' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function refused(string $code, string $message): array
    {
        return [
            'can_cancel' => false,
            'refundable' => false,
            'refund_amount' => 0.0,
            'points_back' => 0.0,
            'code' => $code,
            'message' => $message,
            'cancel_from' => null,
        ];
    }
}
