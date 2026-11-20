<?php

namespace App\Services\Payments;

use App\Exceptions\InsufficientStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockHold;
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
 */
class PaymentSettlement
{
    private const PLACED = 'placed';

    private const CONFIRMED = 'confirmed';

    private const REFUND = 'refund';

    private const DUPLICATE = 'duplicate';

    /** How late after the pay-by time a payment is still taken, for the webhook's own delay. */
    public const LATE_PAYMENT_GRACE_MINUTES = 2;

    public function __construct(
        private PaymentGateway $gateway,
        private StockReservation $stock,
        private OrderAnnouncer $announcer,
        private OrderNotice $notice,
    ) {}

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

            $locked->forceFill([
                'payment_status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'payment_method' => $channel,
                'idempotency_key' => $paymentId,
            ])->save();

            if (abs($session->amount - (float) ($order->total_amount ?? $order->total_price)) > 0.009) {
                Log::warning('A payment did not match its order total.', [
                    'order_id' => $order->getKey(),
                    'payment_id' => $locked->getKey(),
                    'paid' => $session->amount,
                ]);
            }

            return [$order, $this->moveOrder($order, $locked)];
        });

        if ($result === null) {
            return;
        }

        [$order, $outcome] = $result;

        $this->announce($order->refresh(), $outcome);
    }

    /** Inside the transaction, with the order locked. Returns what happened. */
    private function moveOrder(Order $order, Payment $payment): string
    {
        // Already paid through another checkout: this one is owed back.
        if ($order->isPaid()) {
            $payment->forceFill([
                'refund_amount' => $this->total($order),
                'refund_status' => 'pending',
            ])->save();

            Log::warning('An order was paid twice; the second payment is owed back.', [
                'order_id' => $order->getKey(),
                'payment_id' => $payment->getKey(),
            ]);

            return self::DUPLICATE;
        }

        $status = (string) $order->status;
        $cancelledBySystem = $status === OrderStatus::CANCELLED && $order->cancelled_by === null;

        if ($order->isAdvance()) {
            $canConfirm = in_array($status, [OrderStatus::AWAITING_PAYMENT, OrderStatus::ACCEPTED], true)
                || ($cancelledBySystem && $order->scheduled_for !== null && $order->scheduled_for->isFuture());

            if (! $canConfirm) {
                return $this->owe($order, 'Your payment arrived after this order had closed.');
            }

            // Decided 2026-11-20: the pay-by time is the latest a payment is
            // accepted, even if the sweep has not cancelled the order yet. A
            // checkout page can outlive it (Xendit keeps one open at least ten
            // minutes), so a payment past it is owed back rather than booking
            // an order the kitchen can no longer make.
            $due = $order->paymentDueAt();

            if ($due !== null && CarbonImmutable::now()->greaterThan($due->addMinutes(self::LATE_PAYMENT_GRACE_MINUTES))) {
                return $this->owe($order, 'Your payment arrived after the pay-by time, so the order could not go ahead.');
            }

            return $this->reopen($order, OrderStatus::CONFIRMED, self::CONFIRMED);
        }

        if ($status !== OrderStatus::AWAITING_PAYMENT && ! $cancelledBySystem) {
            return $this->owe($order, 'Your payment arrived after this order had closed.');
        }

        try {
            $this->secureStock($order);
        } catch (InsufficientStock) {
            return $this->owe($order, $status === OrderStatus::AWAITING_PAYMENT
                ? 'An item sold out before your payment was confirmed.'
                : 'Your payment arrived after the 10-minute payment window, and an item has sold out since.');
        }

        return $this->reopen($order, OrderStatus::PLACED, self::PLACED);
    }

    private function reopen(Order $order, string $status, string $outcome): string
    {
        $order->forceFill([
            'status' => $status,
            'payment_status' => Order::PAYMENT_PAID,
            'cancellation_reason' => null,
            'cancelled_by' => null,
            'refund_owed' => 0,
            'closed_at' => null,
        ])->save();

        return $outcome;
    }

    /** Money taken for an order that cannot go ahead: it stays (or becomes) cancelled, owed back in full. */
    private function owe(Order $order, string $reason): string
    {
        $order->forceFill([
            'status' => OrderStatus::CANCELLED,
            'payment_status' => Order::PAYMENT_PAID,
            'refund_owed' => $this->total($order),
            'cancellation_reason' => $reason,
        ])->save();

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

    private function announce(Order $order, string $outcome): void
    {
        match ($outcome) {
            // Now the shop has it: its number for the day, onto the staff boards, and
            // the customer's "order placed" notice.
            self::PLACED => $this->placed($order),
            self::CONFIRMED => $this->confirmed($order),
            self::REFUND => $this->announcer->statusChanged($order),
            default => null,
        };
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
