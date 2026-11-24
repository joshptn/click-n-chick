<?php

namespace App\Services\Payments;

use App\Exceptions\InsufficientStock;
use App\Jobs\SendGuestTrackingLink;
use App\Mail\GuestOrderNotice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockHold;
use App\Services\Loyalty\LoyaltyPoints;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Stock\StockReservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records a payment the provider has confirmed, once (FR-04.3, FR-04.4), and
 * moves the order on.
 *
 * Nothing here trusts a webhook body: every path starts from the session as
 * the provider reports it when asked (reconcile, or the session a cancel call
 * returned), so a forged notification can at most make us ask.
 *
 *   immediate, awaiting_payment  ->  placed, joins the queue, stock sold
 *   advance, awaiting_payment    ->  confirmed
 *   paid after the system cancelled it unpaid (decided 2026-11-17): revived if
 *   it still can be (stock still there; an advance order's collection time not
 *   yet passed), otherwise left cancelled with the full amount owed back
 *   an advance order the customer cancelled themselves is revived the same
 *   way; one the store cancelled is not (decided 2026-11-23)
 *   a second payment on an order already paid: owed back in full
 *
 * Every refund is recorded on the payment and started by PaymentRefunds.
 */
class PaymentSettlement
{
    private const PLACED = 'placed';

    private const CONFIRMED = 'confirmed';

    private const REFUND = 'refund';

    private const EXTRA = 'extra';

    /** How late after the pay-by time a payment is still taken, for the webhook's own delay. */
    public const LATE_PAYMENT_GRACE_MINUTES = 2;

    /**
     * Why a paid order could not go ahead. Stored as the cancellation reason
     * and matched exactly, so the return screen and the guest's email can say
     * "Payment received, but your order was cancelled" (decided 2026-11-23).
     */
    public const SOLD_OUT = 'An item sold out before your payment was confirmed.';

    public const SOLD_OUT_AFTER_WINDOW = 'Your payment arrived after the 10-minute payment window, and an item has sold out since.';

    public const PAST_PAY_BY = 'Your payment arrived after the pay-by time, so the order could not go ahead.';

    public const CLOSED = 'Your payment arrived after this order had closed.';

    /** A late payment revived the order, but its points had been spent meanwhile (decided 2026-11-24). */
    public const POINTS_GONE = 'Your payment arrived late, and your points had been used on another order since.';

    public const REASONS = [self::SOLD_OUT, self::SOLD_OUT_AFTER_WINDOW, self::PAST_PAY_BY, self::CLOSED, self::POINTS_GONE];

    public function __construct(
        private PaymentGateway $gateway,
        private StockReservation $stock,
        private OrderAnnouncer $announcer,
        private OrderNotice $notice,
        private PaymentRefunds $refunds,
        private LoyaltyPoints $points,
    ) {}

    /**
     * An order whose whole bill is paid with points (decided 2026-11-24): no
     * money to take, so no checkout. It is recorded as paid with points and
     * moves on exactly as a paid one would. Returns the order as it now stands.
     */
    public function settleWithPoints(Order $order): Order
    {
        $result = DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null
                || $locked->payments()->verified()->exists()
                || round((float) ($locked->total_amount ?? $locked->total_price), 2) > 0) {
                return null;
            }

            $payment = Payment::create([
                'order_id' => $locked->getKey(),
                'user_id' => $locked->user_id,
                'payment_method' => Payment::METHOD_POINTS,
                'payment_status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'idempotency_key' => 'points-'.$locked->getKey(),
            ]);

            return [$locked, $this->moveOrder($locked, $payment), $payment];
        });

        if ($result !== null) {
            [$settled, $outcome, $payment] = $result;

            $this->announce($settled->refresh(), $outcome, $payment->refresh());
        }

        return $order->refresh();
    }

    /** Whether a cancelled order was cancelled because its payment could not place it. */
    public static function couldNotPlace(Order $order): bool
    {
        return $order->status === OrderStatus::CANCELLED
            && in_array($order->cancellation_reason, self::REASONS, true);
    }

    /**
     * Ask the provider how a pending payment stands, and act on the answer.
     *
     * @throws PaymentGatewayException
     */
    public function reconcile(Payment $payment): Payment
    {
        // A closed checkout is still asked about: a QR code scanned before it
        // was closed can be paid after (decided 2026-11-17: revive, or owe back).
        if ($payment->payment_status === Payment::STATUS_PAID || $payment->provider_reference_id === null) {
            return $payment;
        }

        return $this->apply($payment, $this->gateway->getSession($payment->provider_reference_id));
    }

    /** Act on a session the provider has just reported. */
    public function apply(Payment $payment, GatewaySession $session): Payment
    {
        if ($session->isCompleted()) {
            $this->settle($payment, $session);
        } elseif (in_array($session->status, [GatewaySession::EXPIRED, GatewaySession::CANCELED], true)) {
            Payment::query()
                ->whereKey($payment->getKey())
                ->where('payment_status', Payment::STATUS_PENDING)
                ->update(['payment_status' => Payment::STATUS_CLOSED]);
        }

        return $payment->refresh();
    }

    private function settle(Payment $payment, GatewaySession $session): void
    {
        $channel = $this->channel($session);

        $result = DB::transaction(function () use ($payment, $session, $channel) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            // Processed exactly once (FR-04.4): a retried webhook, a second
            // webhook for the same payment, and the customer's own status check
            // all arrive here; only the first gets past.
            if ($locked === null || $locked->payment_status === Payment::STATUS_PAID) {
                return null;
            }

            $paymentId = $session->paymentId ?? $session->id;

            if (Payment::query()->where('idempotency_key', $paymentId)->whereKeyNot($locked->getKey())->exists()) {
                return null;
            }

            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->first();

            // Asked before this payment is recorded, under the order's lock: a
            // second payment settling at the same moment waits here and then
            // sees the first.
            $alreadyPaid = $order->payments()->verified()->whereKeyNot($locked->getKey())->exists();

            $locked->forceFill([
                'payment_status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'payment_method' => $channel,
                'idempotency_key' => $paymentId,
            ])->save();

            if (abs($session->amount - $this->total($order)) > 0.009) {
                Log::warning('A payment did not match its order total.', [
                    'order_id' => $order->getKey(),
                    'payment_id' => $locked->getKey(),
                    'paid' => $session->amount,
                ]);
            }

            // Already paid through another checkout: this one is owed back in full.
            if ($alreadyPaid) {
                Log::warning('An order was paid twice; the second payment is owed back.', [
                    'order_id' => $order->getKey(),
                    'payment_id' => $locked->getKey(),
                ]);

                $this->refunds->owe($locked, $this->total($order), PaymentRefunds::REASON_DUPLICATE);

                return [$order, self::EXTRA, $locked];
            }

            return [$order, $this->moveOrder($order, $locked), $locked];
        });

        if ($result === null) {
            return;
        }

        [$order, $outcome, $paid] = $result;

        $this->announce($order->refresh(), $outcome, $paid->refresh());
    }

    /** Inside the transaction, with the order locked. Returns what happened. */
    private function moveOrder(Order $order, Payment $payment): string
    {
        $status = (string) $order->status;
        $cancelledBy = $order->cancelled_by;
        $cancelledBySystem = $status === OrderStatus::CANCELLED && $cancelledBy === null;

        if ($order->isAdvance()) {
            // Decided 2026-11-23: one the customer cancelled themselves is
            // revived too; one the store cancelled or declined never is.
            $cancelledByCustomer = $status === OrderStatus::CANCELLED
                && $cancelledBy !== null
                && $order->user_id !== null
                && (int) $cancelledBy === (int) $order->user_id;

            $canConfirm = in_array($status, [OrderStatus::AWAITING_PAYMENT, OrderStatus::ACCEPTED], true)
                || (($cancelledBySystem || $cancelledByCustomer) && $order->scheduled_for !== null && $order->scheduled_for->isFuture());

            if (! $canConfirm) {
                return $this->owe($order, $payment, self::CLOSED);
            }

            // Decided 2026-11-20: the pay-by time is the latest a payment is
            // accepted, even if the sweep has not cancelled the order yet. A
            // checkout page can outlive it (Xendit keeps one open at least ten
            // minutes), so a payment past it is owed back rather than booking
            // an order the kitchen can no longer make.
            $due = $order->paymentDueAt();

            if ($due !== null && CarbonImmutable::now()->greaterThan($due->addMinutes(self::LATE_PAYMENT_GRACE_MINUTES))) {
                return $this->owe($order, $payment, self::PAST_PAY_BY);
            }

            // Its points: already held when the payment opened, unless it was
            // cancelled and gave them back since.
            if (! $this->points->hold($order)) {
                return $this->owe($order, $payment, self::POINTS_GONE);
            }

            return $this->reopen($order, OrderStatus::CONFIRMED, self::CONFIRMED);
        }

        if ($status !== OrderStatus::AWAITING_PAYMENT && ! $cancelledBySystem) {
            return $this->owe($order, $payment, self::CLOSED);
        }

        try {
            $this->secureStock($order);
        } catch (InsufficientStock) {
            return $this->owe($order, $payment, $status === OrderStatus::AWAITING_PAYMENT
                ? self::SOLD_OUT
                : self::SOLD_OUT_AFTER_WINDOW);
        }

        // Its points: held at checkout, unless the order lapsed and gave them
        // back since (a late payment); then taken again if the balance still can.
        if (! $this->points->hold($order)) {
            return $this->owe($order, $payment, self::POINTS_GONE);
        }

        return $this->reopen($order, OrderStatus::PLACED, self::PLACED);
    }

    private function reopen(Order $order, string $status, string $outcome): string
    {
        $order->forceFill([
            'status' => $status,
            'cancellation_reason' => null,
            'cancelled_by' => null,
            'closed_at' => null,
        ])->save();

        return $outcome;
    }

    /** Money taken for an order that cannot go ahead: it stays (or becomes) cancelled, owed back in full. */
    private function owe(Order $order, Payment $payment, string $reason): string
    {
        $order->forceFill([
            'status' => OrderStatus::CANCELLED,
            'cancellation_reason' => $reason,
        ])->save();

        $this->refunds->owe($payment, $this->total($order), PaymentRefunds::REASON_CANCELLATION);

        return self::REFUND;
    }

    private function total(Order $order): float
    {
        return round((float) ($order->total_amount ?? $order->total_price), 2);
    }

    /**
     * The order's units, sold. Held units still inside their window are
     * confirmed; if the hold lapsed (or was released when the order was
     * cancelled) the units are held again first, which fails if they are gone.
     *
     * @throws InsufficientStock
     */
    private function secureStock(Order $order): void
    {
        $order->stockHolds()
            ->where('status', StockHold::HOLDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => StockHold::EXPIRED]);

        $live = $order->stockHolds()->whereIn('status', [StockHold::HOLDING, StockHold::CONFIRMED])->exists();

        if (! $live) {
            $lines = $order->items()->get(['food_id', 'quantity'])
                ->map(fn ($item) => ['food_id' => (int) $item->food_id, 'quantity' => (int) $item->quantity])
                ->all();

            $this->stock->hold($order, $lines);
        }

        $this->stock->confirm($order);
    }

    private function announce(Order $order, string $outcome, Payment $payment): void
    {
        match ($outcome) {
            // Now the shop has it: its number for the day, onto the staff boards, and
            // the customer's "order placed" notice.
            self::PLACED => $this->placed($order),
            self::CONFIRMED => $this->confirmed($order),
            self::REFUND => $this->cancelledAfterPayment($order),
            self::EXTRA => $this->paidTwice($order, $payment),
            default => null,
        };
    }

    /**
     * Paid, but the order could not go ahead (decided 2026-11-23): the
     * account holder's notice reads "Payment received, but your order was
     * cancelled", and a guest is emailed the same with the tracking link.
     */
    private function cancelledAfterPayment(Order $order): void
    {
        $this->announcer->statusChanged($order);
        $this->announcer->toGuest($order, GuestOrderNotice::PAYMENT_CANCELLED);
    }

    /** The extra payment is on its way back (or to collect, for QR Ph). */
    private function paidTwice(Order $order, Payment $payment): void
    {
        $this->announcer->toCustomer($order, $this->notice->customerExtraPayment($order, $payment));
        $this->announcer->toGuest($order, GuestOrderNotice::EXTRA_PAYMENT, $payment);
    }

    /**
     * A paid advance order: the customer's notice, the staff boards, and the
     * Store Agents told it waits in To Confirm (decided 2026-11-20).
     */
    private function confirmed(Order $order): void
    {
        $this->announcer->statusChanged($order);
        $this->announcer->toStaff($order, $this->notice->staffAdvancePaid($order));
    }

    private function placed(Order $order): void
    {
        $order->assignOrderNumber();
        $this->announcer->announce($order, 'create', 'create');

        // A guest's tracking link, now that it is a real order (FR-02.13a,
        // decided 2026-11-22). Once: settle() lets only the first confirmation
        // of a payment get this far. A payment that could not place the order
        // (owed back) sends nothing for now (decided 2026-11-23).
        if ($order->isGuest()) {
            SendGuestTrackingLink::dispatch($order->getKey());
        }
    }

    private function channel(GatewaySession $session): ?string
    {
        if ($session->paymentRequestId === null) {
            return null;
        }

        try {
            return $this->gateway->paymentChannel($session->paymentRequestId);
        } catch (PaymentGatewayException $e) {
            Log::info('The payment method could not be read back from the provider.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
