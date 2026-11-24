<?php

namespace App\Services\Orders;

use App\Mail\GuestOrderNotice;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentRefunds;
use App\Services\Payments\PaymentSettlement;
use App\Services\Store\StoreAvailability;
use App\Services\Store\StoreContact;
use Carbon\CarbonImmutable;

class OrderNotice
{
    public const TYPE_ORDER = 'order';

    public const TYPE_ADVANCE = 'advance_order';

    public const TYPE_ADVANCE_REQUEST = 'advance_request';

    public const TYPE_ADVANCE_DUE = 'advance_due';

    public const TYPE_ADVANCE_PAYMENT = 'advance_payment';

    /** The automatic payment reminders, told apart from a Store Agent's by their titles. */
    public const DUE_SOON_TITLE = 'Payment due soon';

    public const DUE_TODAY_UNPAID_TITLE = 'Payment needed today';

    /** A pay-by time this close is spelled out in minutes. */
    public const URGENT_MINUTES = 30;

    public function __construct(
        private StoreAvailability $store,
        private StoreContact $contact,
    ) {}

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
            'body' => "{$this->requestor($order)}'s request for {$this->collectAt($order)} - "
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
        $payment = $order->paidPayment;
        $owed = $payment !== null && $payment->owesRefund() ? (float) $payment->refund_amount : 0.0;

        return [
            'title' => 'Advance order cancelled',
            'body' => "{$this->staffName($order)} was cancelled by the customer, due {$this->collectAt($order)}. "
                .($owed > 0 ? "{$this->peso($owed)} is owed back to them." : 'No refund is owed.'),
            'type' => self::TYPE_ADVANCE,
        ];
    }

    /**
     * An advance order was paid and waits in To Confirm (decided 2026-11-20,
     * Store Agents only): started at once if it is for today, scheduled if it
     * is for a later day. Typed advance_order, so the bell opens Advance Orders.
     *
     * @return array{title: string, body: string, type: string}
     */
    public function staffAdvancePaid(Order $order): array
    {
        $collect = $this->collection($order);
        $today = $collect !== null && $collect->isSameDay(CarbonImmutable::now($this->store->timezone()));

        return [
            'title' => 'Advance order paid',
            'body' => $today
                ? "{$this->staffName($order)} was paid and is for today, {$collect->format('g:i A')}. "
                    .'Start preparing it from To Confirm.'
                : "{$this->staffName($order)} was paid for {$this->collectAt($order)}. "
                    .'Schedule it from To Confirm.',
            'type' => self::TYPE_ADVANCE,
        ];
    }

    /** @return array{title: string, body: string, type: string} */
    public function staffDueToday(Order $order): array
    {
        $at = $this->collection($order)?->format('g:i A') ?? 'today';

        // Only paid orders are announced (advance:notify-due); an unpaid one is
        // chased with the customer instead (customerDueTodayUnpaid).
        return [
            'title' => 'Advance order due today',
            'body' => "{$this->staffName($order)} is due for collection at {$at} today. "
                .'Start preparing it from Scheduled Orders when the kitchen is ready.',
            'type' => self::TYPE_ADVANCE_DUE,
        ];
    }

    /** @return array{title: string, body: string, type: string} */
    public function paymentReminder(Order $order): array
    {
        return [
            'title' => 'Payment still needed',
            'body' => "Your advance order for {$this->collectAt($order)} is not paid yet. "
                .$this->payBy($order),
            'type' => self::TYPE_ADVANCE_PAYMENT,
        ];
    }

    /**
     * The collection-day reminder for an order still unpaid (decided
     * 2026-11-20), sent by advance:notify-due.
     *
     * @return array{title: string, body: string, type: string}
     */
    public function customerDueTodayUnpaid(Order $order): array
    {
        return [
            'title' => self::DUE_TODAY_UNPAID_TITLE,
            'body' => 'Your advance order is scheduled for today. Please complete your payment. '
                .$this->payBy($order),
            'type' => self::TYPE_ADVANCE_PAYMENT,
        ];
    }

    /**
     * The automatic reminder ahead of the pay-by time (decided 2026-11-20),
     * sent by advance:remind-payment.
     *
     * @return array{title: string, body: string, type: string}
     */
    public function paymentDueSoon(Order $order): array
    {
        return [
            'title' => self::DUE_SOON_TITLE,
            'body' => "Your advance order for {$this->collectAt($order)} is not paid yet. ".$this->payBy($order),
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
            'create' => "Your order {$order->reference()} has been placed. We will confirm it shortly.",
            OrderStatus::CONFIRMED => "Good news! Your order {$order->reference()} has been confirmed.",
            OrderStatus::PREPARING => "Your order {$order->reference()} is being prepared now.",
            OrderStatus::READY_FOR_PICKUP => "Your order {$order->reference()} is ready for collection at the store.",
            OrderStatus::ON_THE_WAY => "Your order {$order->reference()} is on the way.",
            OrderStatus::COMPLETED => "Your order {$order->reference()} has been completed. Thank you for ordering with us!",
            OrderStatus::DELIVERED => "Your order {$order->reference()} has been delivered. Thank you for ordering with us!",
            OrderStatus::CANCELLED => $this->cancellationBody($order, "your order {$order->reference()}"),
            default => "There is an update regarding your order {$order->reference()}.",
        };

        return ['title' => 'Order '.$label, 'body' => $body];
    }

    /** The customer's name, or "A customer" (decided 2026-11-20: a request is known by its requestor). */
    private function requestor(Order $order): string
    {
        $name = trim(($order->user->first_name ?? '').' '.($order->user->last_name ?? ''));

        return $name === '' ? 'A customer' : $name;
    }

    /** "Order A-007" once accepted, otherwise "Juan Dela Cruz's request". */
    private function staffName(Order $order): string
    {
        $number = $order->number();

        return $number !== null ? "Order {$number}" : "{$this->requestor($order)}'s request";
    }

    private function cancellationBody(Order $order, string $subject): string
    {
        // Paid, but the payment could not place it: said the same way as on the
        // return screen (decided 2026-11-23).
        if (PaymentSettlement::couldNotPlace($order) && ($notice = $this->paymentNotice($order)) !== null) {
            return $notice['message'];
        }

        $byShop = $order->cancelled_by !== null
            && (int) $order->cancelled_by !== (int) $order->user_id;

        $opening = $byShop
            ? 'The store cancelled '.$subject
            : ucfirst($subject).' has been cancelled';

        return $this->withReason($order, $opening).' '.$this->refundSentence($order);
    }

    private function refundSentence(Order $order): string
    {
        $payment = $order->paidPayment;

        if ($payment === null) {
            return 'Nothing had been paid, so there is nothing to refund.';
        }

        // Paid and nothing owed back: the store chose not to refund (BR-21a).
        if (! in_array($payment->refundState(), ['refunding', 'by_hand', 'refunded'], true)) {
            return 'This order is not refunded.';
        }

        return $this->refundLine($order, $payment);
    }

    /**
     * Where a refund stands, for the customer (wording approved 2026-11-23).
     * A GCash refund Xendit refused is not announced: it reads as pending.
     */
    public function refundLine(Order $order, Payment $payment): string
    {
        $amount = $this->peso($payment->refund_amount);

        if ($payment->refund_status === Payment::REFUND_REFUNDED) {
            return PaymentRefunds::throughXendit($payment)
                ? "Your {$amount} refund for ".lcfirst($order->title()).' has been sent back to your GCash.'
                : "The store has sent your {$amount} refund for ".lcfirst($order->title()).'.';
        }

        if ($payment->refund_status === Payment::REFUND_PENDING && $payment->isGcash()) {
            return "{$amount} is on its way back to your GCash.";
        }

        if ($payment->payment_method === Payment::METHOD_QRPH) {
            return "Please contact {$this->contact->theStore()} to get your {$amount} back.";
        }

        return "A full refund of {$amount} is pending.";
    }

    /** @return array{title: string, body: string, type: string} */
    public function customerRefunded(Order $order, Payment $payment): array
    {
        return [
            'title' => 'Refund sent',
            'body' => $this->refundLine($order, $payment),
            'type' => $order->isAdvance() ? self::TYPE_ADVANCE : self::TYPE_ORDER,
        ];
    }

    /** @return array{title: string, body: string, type: string} */
    public function customerExtraPayment(Order $order, Payment $payment): array
    {
        $amount = $this->peso($payment->refund_amount);
        $which = lcfirst($order->title());

        $body = match (true) {
            $payment->refund_status === Payment::REFUND_REFUNDED => "You paid twice for {$which}. The extra {$amount} has been refunded.",
            $payment->payment_method === Payment::METHOD_QRPH => "You paid twice for {$which}. Please contact {$this->contact->theStore()} to get the extra {$amount} back.",
            $payment->isGcash() => "You paid twice for {$which}. The extra {$amount} is on its way back to your GCash.",
            default => "You paid twice for {$which}. The extra {$amount} is being refunded.",
        };

        return [
            'title' => 'You paid twice',
            'body' => $body,
            'type' => $order->isAdvance() ? self::TYPE_ADVANCE : self::TYPE_ORDER,
        ];
    }

    /**
     * What the return screen says when a payment did not simply place the
     * order (decided 2026-11-23): paid, but the order could not go ahead; or
     * paid twice. Null otherwise.
     *
     * @return array{kind: string, title: string, body: string, message: string}|null
     */
    public function paymentNotice(Order $order): ?array
    {
        $payment = $order->paidPayment;

        if ($payment === null) {
            return null;
        }

        if (PaymentSettlement::couldNotPlace($order)) {
            $reason = match ($order->cancellation_reason) {
                PaymentSettlement::SOLD_OUT, PaymentSettlement::SOLD_OUT_AFTER_WINDOW => 'Unfortunately, the items are no longer available.',
                PaymentSettlement::PAST_PAY_BY => 'Unfortunately, it arrived after the time to pay had passed.',
                PaymentSettlement::POINTS_GONE => 'Unfortunately, the points it used had been spent on another order since.',
                default => 'Unfortunately, it arrived after the order had closed.',
            };

            $amount = $this->peso($payment->refund_amount ?? ($order->total_amount ?? $order->total_price));

            $refund = match (true) {
                $payment->refund_status === Payment::REFUND_REFUNDED => "your full refund of {$amount} has been sent.",
                $payment->refund_status === Payment::REFUND_PENDING && $payment->isGcash() => "a full refund of {$amount} is on its way back to your GCash.",
                $payment->payment_method === Payment::METHOD_QRPH => "please contact {$this->contact->theStore()} to get your {$amount} back.",
                default => "a full refund of {$amount} is pending.",
            };

            $title = 'Payment received, but your order was cancelled.';
            $body = "{$reason} We received your {$amount} payment, and {$refund} You can view your order details for updates.";

            return ['kind' => 'cancelled', 'title' => $title, 'body' => $body, 'message' => "{$title} {$body}"];
        }

        $extra = $order->extraPayments()->refunds()->get()->last();

        if ($extra === null) {
            return null;
        }

        $amount = $this->peso($extra->refund_amount);

        $body = match (true) {
            $extra->refund_status === Payment::REFUND_REFUNDED => "You paid twice. The extra {$amount} has been refunded.",
            $extra->payment_method === Payment::METHOD_QRPH => "You paid twice. Please contact {$this->contact->theStore()} to get the extra {$amount} back.",
            default => "You paid twice. The extra {$amount} is being refunded.",
        };

        return ['kind' => 'extra', 'title' => 'You paid twice', 'body' => $body, 'message' => $body];
    }

    /**
     * A guest's email (decided 2026-11-23), or null when there is nothing to
     * say any more.
     *
     * @return array{subject: string, heading: string, body: string}|null
     */
    public function guestEmail(Order $order, string $kind, ?Payment $payment = null): ?array
    {
        $title = lcfirst($order->title());

        return match ($kind) {
            GuestOrderNotice::CANCELLED => $order->status !== OrderStatus::CANCELLED ? null : [
                'subject' => 'Your '.$title.' was cancelled',
                'heading' => 'Your order was cancelled',
                'body' => $this->cancellationBody($order, 'your '.$title),
            ],
            GuestOrderNotice::PAYMENT_CANCELLED => ($notice = $this->paymentNotice($order)) === null || $notice['kind'] !== 'cancelled' ? null : [
                'subject' => 'Payment received, but your order was cancelled',
                'heading' => $notice['title'],
                'body' => $notice['body'],
            ],
            GuestOrderNotice::EXTRA_PAYMENT => $payment === null ? null : [
                'subject' => 'You paid twice for '.$title,
                'heading' => 'You paid twice',
                'body' => $this->customerExtraPayment($order, $payment)['body'],
            ],
            GuestOrderNotice::REFUNDED => $payment === null || $payment->refund_status !== Payment::REFUND_REFUNDED ? null : [
                'subject' => 'Your refund for '.$title,
                'heading' => 'Refund sent',
                'body' => $this->refundLine($order, $payment),
            ],
            default => null,
        };
    }

    private function withReason(Order $order, string $opening): string
    {
        $reason = trim((string) $order->cancellation_reason);

        return $reason === '' ? $opening.'.' : $opening.': '.rtrim($reason, '.').'.';
    }

    /**
     * When the customer must pay, and what happens if they do not (decided
     * 2026-11-20). With a preparation time the reason is given, and a window
     * of 30 minutes or less says how many minutes are left.
     */
    private function payBy(Order $order): string
    {
        $due = $order->paymentDueAt()?->setTimezone($this->store->timezone());

        if ($due === null) {
            return 'Pay within 24 hours to confirm it, or the store will cancel it.';
        }

        $at = $this->moment($due);
        $minutesLeft = (int) floor(CarbonImmutable::now()->diffInMinutes($due, false));

        if ($minutesLeft <= self::URGENT_MINUTES) {
            $left = max(0, $minutesLeft);

            return "Please pay within {$left} minute".($left === 1 ? '' : 's').", by {$at}"
                .($order->paysBeforePreparation() ? ', so the store has time to prepare it' : '')
                .'. If it is not paid by then, the store will cancel it.';
        }

        if ($order->paysBeforePreparation()) {
            return "The store needs {$order->preparation_time} minutes to prepare it, so please pay by {$at}. "
                .'If it is not paid by then, the store will cancel it.';
        }

        return "Please pay by {$at} to confirm it. If it is not paid by then, the store will cancel it.";
    }

    /** "4:30 PM today", "9:00 AM tomorrow", "9:00 AM, 23 Nov". */
    private function moment(CarbonImmutable $at): string
    {
        $today = CarbonImmutable::now($this->store->timezone())->startOfDay();
        $days = (int) $today->diffInDays($at->startOfDay(), false);

        return match ($days) {
            0 => $at->format('g:i A').' today',
            1 => $at->format('g:i A').' tomorrow',
            default => $at->format('g:i A, j M'),
        };
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
