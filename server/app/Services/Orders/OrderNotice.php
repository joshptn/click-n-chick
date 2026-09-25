<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;

class OrderNotice
{
    public const TYPE_ORDER = 'order';

    public const TYPE_ADVANCE = 'advance_order';

    public const TYPE_ADVANCE_REQUEST = 'advance_request';

    public const TYPE_ADVANCE_DUE = 'advance_due';

    public const TYPE_ADVANCE_PAYMENT = 'advance_payment';

    public function __construct(private StoreAvailability $store) {}

    /**
     * @return array{title: string, body: string, type: string}
     */
    public function customer(Order $order, string $event): array
    {
        if ($order->isAdvance()) {
            $advance = $this->advance($order, $event);

            if ($advance !== null) {
                return $advance + ['type' => self::TYPE_ADVANCE];
            }
        }

        return $this->immediate($order, $event) + ['type' => self::TYPE_ORDER];
    }

    /** @return array{title: string, body: string, type: string} */
    public function staffNewRequest(Order $order): array
    {
        $items = (int) $order->items()->sum('quantity');

        return [
            'title' => 'New advance request',
            'body' => "Request #{$order->id} for {$this->collectAt($order)} - "
                ."{$items} item(s), {$this->peso($order->total_amount ?? $order->total_price)}. "
                .'It needs accepting or rejecting.',
            'type' => self::TYPE_ADVANCE_REQUEST,
        ];
    }
    
    /**
     * @return array{title: string, body: string, type: string}
     */
    public function staffCancelled(Order $order): array
    {
        $owed = (float) ($order->refund_owed ?? 0);

        return [
            'title' => 'Advance order cancelled',
            'body' => "The customer cancelled order #{$order->id}, due {$this->collectAt($order)}. "
                .($owed > 0 ? "{$this->peso($owed)} is owed back to them." : 'No refund is owed.'),
            'type' => self::TYPE_ADVANCE,
        ];
    }

    /** @return array{title: string, body: string, type: string} */
    public function staffDueToday(Order $order): array
    {
        $at = $this->collection($order)?->format('g:i A') ?? 'today';

        return [
            'title' => 'Advance order due today',
            'body' => $order->isPaid()
                ? "Order #{$order->id} is due for collection at {$at} today. Move it into preparing when the kitchen is ready."
                : "Order #{$order->id} is due at {$at} today but is still unpaid, so it cannot be prepared. Contact the customer or cancel it.",
            'type' => self::TYPE_ADVANCE_DUE,
        ];
    }

    /** @return array{title: string, body: string, type: string} */
    public function paymentReminder(Order $order): array
    {
        $due = $order->paymentDueAt()?->setTimezone($this->store->timezone());

        return [
            'title' => 'Payment still needed',
            'body' => "Your advance order for {$this->collectAt($order)} is not paid yet."
                .($due ? " Please pay by {$due->format('g:i A, j M')}" : ' Please pay soon')
                .' or the store will release your slot.',
            'type' => self::TYPE_ADVANCE_PAYMENT,
        ];
    }

    /** @return array{title: string, body: string}|null */
    private function advance(Order $order, string $event): ?array
    {
        $when = $this->collectAt($order);

        return match ($event) {
            'create', OrderStatus::SUBMITTED => [
                'title' => 'Request sent',
                'body' => "Your advance order for {$when} is with the store. "
                    .'We will let you know as soon as a Store Agent has reviewed it.',
            ],

            OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT => [
                'title' => 'Request accepted',
                'body' => "The store accepted your advance order for {$when}. "
                    .$this->payBy($order),
            ],

            OrderStatus::CONFIRMED => [
                'title' => 'Advance order confirmed',
                'body' => "Payment received. Your order for {$when} is booked - "
                    .'nothing happens in the kitchen until then.',
            ],

            OrderStatus::SCHEDULED => [
                'title' => 'You are booked in',
                'body' => "Your advance order is scheduled for {$when}. "
                    .'We start preparing it on the day, so there is nothing to do until then.',
            ],

            OrderStatus::REJECTED => [
                'title' => 'Request declined',
                'body' => $this->withReason(
                    $order,
                    "The store could not take on your advance order for {$when}"
                ).' Nothing was charged.',
            ],

            OrderStatus::CANCELLED => [
                'title' => 'Advance order cancelled',
                'body' => $this->cancellationBody($order, "your advance order for {$when}"),
            ],

            default => null,
        };
    }

    /** @return array{title: string, body: string} */
    private function immediate(Order $order, string $event): array
    {
        $label = is_string($event) && OrderStatus::isKnown($event)
            ? OrderStatus::label($event)
            : ucfirst((string) $event);

        $body = match ($event) {
            'create' => "Your order #{$order->id} has been placed. We will confirm it shortly.",
            OrderStatus::CONFIRMED => "Good news! Your order #{$order->id} has been confirmed.",
            OrderStatus::PREPARING => "Your order #{$order->id} is being prepared now.",
            OrderStatus::READY_FOR_PICKUP => "Your order #{$order->id} is ready for collection at the store.",
            OrderStatus::ON_THE_WAY => "Your order #{$order->id} is on the way.",
            OrderStatus::COMPLETED => "Your order #{$order->id} has been completed. Thank you for ordering with us!",
            OrderStatus::DELIVERED => "Your order #{$order->id} has been delivered. Thank you for ordering with us!",
            OrderStatus::CANCELLED => $this->cancellationBody($order, "your order #{$order->id}"),
            'update' => "Your order #{$order->id} ETC has been updated to {$order->estimated_time_of_completion} minutes.",
            default => "There is an update regarding your order #{$order->id}.",
        };

        return ['title' => 'Order '.$label, 'body' => $body];
    }

    private function cancellationBody(Order $order, string $subject): string
    {
        $byShop = $order->cancelled_by !== null
            && (int) $order->cancelled_by !== (int) $order->user_id;

        $opening = $byShop
            ? 'The store cancelled '.$subject
            : ucfirst($subject).' has been cancelled';

        return $this->withReason($order, $opening).' '.$this->refundSentence($order);
    }

    private function refundSentence(Order $order): string
    {
        $owed = (float) ($order->refund_owed ?? 0);

        if ($owed > 0) {
            return "{$this->peso($owed)} will be refunded to you.";
        }

        return $order->isPaid()
            ? 'As the kitchen had already started, this one is not refunded.'
            : 'Nothing had been paid, so there is nothing to refund.';
    }

    private function withReason(Order $order, string $opening): string
    {
        $reason = trim((string) $order->cancellation_reason);
        return $reason === '' ? $opening.'.' : $opening.': '.rtrim($reason, '.').'.';
    }

    private function payBy(Order $order): string
    {
        $due = $order->paymentDueAt()?->setTimezone($this->store->timezone());

        return $due === null
            ? 'Pay within 24 hours to confirm it.'
            : "Pay by {$due->format('g:i A, j M')} to confirm it.";
    }

    private function collection(Order $order): ?CarbonImmutable
    {
        return $order->scheduled_for === null
            ? null
            : CarbonImmutable::parse($order->scheduled_for)->setTimezone($this->store->timezone());
    }

    private function collectAt(Order $order): string
    {
        return $this->collection($order)?->format('l j F, g:i A') ?? 'your chosen date';
    }

    private function peso(float|string|null $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }
}
