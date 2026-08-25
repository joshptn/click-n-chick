<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Setting;
use Carbon\CarbonImmutable;


class CancellationPolicy
{

    public const DEFAULT_FULL_REFUND_THROUGH = OrderStatus::CONFIRMED;

    public const DEFAULT_ADVANCE_CUTOFF_HOURS = 24;

    public static function thresholds(): array
    {
        return [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::PREPARING];
    }

    public function fullRefundThrough(): string
    {
        $value = Setting::get(Setting::CANCELLATION_FULL_REFUND_THROUGH);

        return in_array($value, self::thresholds(), true) ? $value : self::DEFAULT_FULL_REFUND_THROUGH;
    }

    public function advanceCutoffHours(): int
    {
        return (int) Setting::number(
            Setting::CANCELLATION_ADVANCE_CUTOFF_HOURS,
            self::DEFAULT_ADVANCE_CUTOFF_HOURS
        );
    }
    public function for(Order $order): array
    {
        $status = (string) $order->status;

        if (OrderStatus::isTerminal($status)) {
            return [
                'can_cancel' => false,
                'refundable' => false,
                'refund_amount' => 0.0,
                'code' => 'ORDER_ALREADY_CLOSED',
                'message' => 'This order is already '.strtolower(OrderStatus::label($status)).'.',
                'free_until_status' => null,
                'free_until' => null,
            ];
        }

        $byStatus = $this->statusAllowsRefund($status, $order->order_type);
        $freeUntil = $this->refundDeadline($order);
        $bySchedule = $freeUntil === null || $freeUntil->isFuture();

        $refundable = $byStatus && $bySchedule;

        return [
            'can_cancel' => true,
            'refundable' => $refundable,
            'refund_amount' => $refundable ? round((float) ($order->total_amount ?? $order->total_price), 2) : 0.0,
            'code' => $refundable ? null : ($byStatus ? 'PAST_SCHEDULE_CUTOFF' : 'KITCHEN_STARTED'),
            'message' => $this->message($refundable, $byStatus),
            'free_until_status' => $this->fullRefundThrough(),
            'free_until' => $freeUntil?->toIso8601String(),
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

    private function refundDeadline(Order $order): ?CarbonImmutable
    {
        if ($order->scheduled_for === null) {
            return null;
        }

        return CarbonImmutable::parse($order->scheduled_for)->subHours($this->advanceCutoffHours());
    }

    private function statusAllowsRefund(string $status, ?string $fulfilmentType): bool
    {
        $chain = OrderStatus::chain($fulfilmentType);

        $at = array_search($status, $chain, true);
        $threshold = array_search($this->fullRefundThrough(), $chain, true);

        if ($at === false || $threshold === false) {
            return false;
        }

        return $at <= $threshold;
    }

    private function message(bool $refundable, bool $byStatus): string
    {
        if ($refundable) {
            return 'You can cancel this order and your payment will be refunded in full.';
        }

        return $byStatus
            ? 'You can still cancel, but this is too close to your scheduled date to be refunded.'
            : 'You can still cancel, but the kitchen has already started, so this order can no longer be refunded.';
    }
}
