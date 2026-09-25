<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Setting;

class CancellationPolicy
{
    public const DEFAULT_FULL_REFUND_THROUGH = OrderStatus::CONFIRMED;

    public const ALREADY_CLOSED = 'ORDER_ALREADY_CLOSED';

    public const KITCHEN_STARTED = 'KITCHEN_STARTED';

    public const NOTHING_PAID = 'NOTHING_PAID';

    /** @return array<int, string> */
    public static function thresholds(): array
    {
        return [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::PREPARING];
    }

    public function fullRefundThrough(): string
    {
        $value = Setting::get(Setting::CANCELLATION_FULL_REFUND_THROUGH);

        return in_array($value, self::thresholds(), true) ? $value : self::DEFAULT_FULL_REFUND_THROUGH;
    }

    /** @return array<string, mixed> */
    public function for(Order $order): array
    {
        $status = (string) $order->status;

        if (OrderStatus::isTerminal($status)) {
            return [
                'can_cancel' => false,
                'refundable' => false,
                'refund_amount' => 0.0,
                'code' => self::ALREADY_CLOSED,
                'message' => 'This order is already '.strtolower(OrderStatus::label($status)).'.',
                'refundable_through' => null,
            ];
        }

        $code = match (true) {
            ! $order->isPaid() => self::NOTHING_PAID,
            ! $this->statusAllowsRefund($order) => self::KITCHEN_STARTED,
            default => null,
        };

        $refundable = $code === null;

        return [
            'can_cancel' => true,
            'refundable' => $refundable,
            'refund_amount' => $refundable
                ? round((float) ($order->total_amount ?? $order->total_price), 2)
                : 0.0,
            'code' => $code,
            'message' => $this->message($code),
            'refundable_through' => $this->refundableThrough($order),
        ];
    }

    public function canCancel(Order $order): bool
    {
        return ! OrderStatus::isTerminal((string) $order->status);
    }

    public function isRefundable(Order $order): bool
    {
        return $this->for($order)['refundable'];
    }

    public function refundOwed(Order $order): float
    {
        return (float) $this->for($order)['refund_amount'];
    }

    public function outcomeMessage(array $decision): string
    {
        if ($decision['refundable']) {
            return 'Your order has been cancelled and your payment will be refunded.';
        }

        return $decision['code'] === self::NOTHING_PAID
            ? 'Your order has been cancelled. You had not paid for it, so there is nothing to refund.'
            : 'Your order has been cancelled. As the kitchen had already started, this one is not refunded.';
    }

    private function commitPoint(Order $order): ?string
    {
        return OrderStatus::next($this->fullRefundThrough(), $order->order_type);
    }

    private function statusAllowsRefund(Order $order): bool
    {
        $chain = $order->statusChain();

        $at = array_search((string) $order->status, $chain, true);
        $commit = array_search($this->commitPoint($order), $chain, true);

        return $at !== false && $commit !== false && $at < $commit;
    }

    private function refundableThrough(Order $order): ?string
    {
        $chain = $order->statusChain();
        $commit = array_search($this->commitPoint($order), $chain, true);

        return $commit === false || $commit === 0 ? null : $chain[$commit - 1];
    }

    private function message(?string $code): string
    {
        return match ($code) {
            null => 'You can cancel this order and your payment will be refunded in full.',
            self::NOTHING_PAID => 'You can cancel this order. You have not paid for it, so there is nothing to refund.',
            default => 'You can still cancel, but the kitchen has already started, so this order can no longer be refunded.',
        };
    }
}
