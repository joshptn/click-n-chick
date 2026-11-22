<?php

namespace App\Services\Payments;

use App\Jobs\SendRefund;
use App\Mail\GuestOrderNotice;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refunds (decided 2026-11-23, BR-21d). The only code that writes a payment's
 * refund columns.
 *
 * A refund is the full amount or nothing, and lives on the payment it returns:
 *
 *   owe        a refund is owed: GCash (or a method not yet known) is sent to
 *              Xendit from the queue; QR Ph, which Xendit cannot refund, is
 *              by hand at once
 *   decline    the store chose No refund: ₱0 owed
 *   send       the queued job's work: one refund per payment, looked for at
 *              Xendit before sending, so a retry never sends a second
 *   apply      Xendit's answer, always read back from Xendit
 *   completed  Xendit confirmed it, or a Store Agent ticked a refund by hand;
 *              the customer is told
 *
 * A refund Xendit refuses becomes a refund by hand, and the customer is not
 * told about the refusal (decided 2026-11-23).
 */
class PaymentRefunds
{
    public const REASON_CUSTOMER = 'REQUESTED_BY_CUSTOMER';

    public const REASON_CANCELLATION = 'CANCELLATION';

    public const REASON_DUPLICATE = 'DUPLICATE';

    public function __construct(
        private PaymentGateway $gateway,
        private OrderAnnouncer $announcer,
        private OrderNotice $notice,
    ) {}

    /** A full refund owed on this payment. Safe inside a transaction: the Xendit call waits for the commit. */
    public function owe(Payment $payment, float $amount, string $reason): void
    {
        $viaXendit = $payment->payment_method !== Payment::METHOD_QRPH;

        $payment->forceFill([
            'refund_amount' => round($amount, 2),
            'refund_status' => $viaXendit ? Payment::REFUND_PENDING : Payment::REFUND_MANUAL,
            'refund_reference_id' => null,
            'refunded_at' => null,
        ])->save();

        if ($viaXendit) {
            SendRefund::dispatch((int) $payment->getKey(), $reason)->afterCommit();
        }
    }

    /** The store cancelled a paid order without a refund (BR-21a): ₱0 owed. */
    public function decline(Payment $payment): void
    {
        $payment->forceFill([
            'refund_amount' => 0,
            'refund_status' => null,
            'refund_reference_id' => null,
            'refunded_at' => null,
        ])->save();
    }

    /**
     * Send a pending refund to Xendit. Throws when Xendit cannot be reached,
     * so the queued job tries again; every try looks for an earlier one first.
     *
     * @throws PaymentGatewayException
     */
    public function send(Payment $payment, string $reason): void
    {
        if ($payment->refund_status !== Payment::REFUND_PENDING) {
            return;
        }

        // Sent by an earlier try whose answer arrived: follow that refund.
        if ($payment->refund_reference_id !== null) {
            $this->apply($payment, $this->gateway->getRefund($payment->refund_reference_id));

            return;
        }

        if ($payment->payment_method === null) {
            $this->learnMethod($payment);
        }

        $paymentId = (string) $payment->idempotency_key;

        if (! $payment->isGcash() || ! str_starts_with($paymentId, 'py-')) {
            $this->toHand($payment, 'not a GCash payment Xendit can refund');

            return;
        }

        $refund = $this->sentBefore($payment, $paymentId);

        if ($refund === null) {
            try {
                $refund = $this->gateway->refund($paymentId, (float) $payment->refund_amount, $payment->refundReference(), $reason);
            } catch (PaymentGatewayException $e) {
                if ($e->status !== null && $e->status >= 400 && $e->status < 500) {
                    $this->toHand($payment, $e->getMessage());

                    return;
                }

                throw $e;
            }
        }

        $payment->forceFill(['refund_reference_id' => $refund->id])->save();
        $this->apply($payment->refresh(), $refund);
    }

    /** Xendit's answer about a refund on this payment. */
    public function apply(Payment $payment, GatewayRefund $refund): void
    {
        if ($refund->paymentId !== null && $payment->idempotency_key !== null && $refund->paymentId !== $payment->idempotency_key) {
            Log::warning('A refund did not belong to the payment it was matched to.', ['payment_id' => $payment->getKey()]);

            return;
        }

        if ($refund->status === GatewayRefund::SUCCEEDED) {
            $this->completed($payment, $refund->id);

            return;
        }

        if ($refund->status === GatewayRefund::FAILED) {
            $this->toHand($payment, 'Xendit reported the refund failed: '.($refund->failureCode ?? 'no reason given'));
        }
    }

    /**
     * Mark a refund refunded, once, and tell the customer. A Store Agent's tick
     * passes the reference they gave; Xendit's passes its refund id.
     */
    public function completed(Payment $payment, ?string $reference): bool
    {
        $done = DB::transaction(function () use ($payment, $reference) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->owesRefund()) {
                return false;
            }

            $locked->forceFill([
                'refund_status' => Payment::REFUND_REFUNDED,
                'refunded_at' => now(),
                'refund_reference_id' => $reference !== null && trim($reference) !== ''
                    ? trim($reference)
                    : $locked->refund_reference_id,
            ])->save();

            return true;
        });

        if (! $done) {
            return false;
        }

        $payment->refresh();
        $order = Order::query()->find($payment->order_id);

        if ($order !== null) {
            $this->announcer->toCustomer($order, $this->notice->customerRefunded($order, $payment));
            $this->announcer->toGuest($order, GuestOrderNotice::REFUNDED, $payment);
        }

        return true;
    }

    /** Refunded through Xendit, as opposed to by hand. */
    public static function throughXendit(Payment $payment): bool
    {
        return str_starts_with((string) $payment->refund_reference_id, 'rfd-');
    }

    private function toHand(Payment $payment, string $why): void
    {
        $moved = Payment::query()
            ->whereKey($payment->getKey())
            ->where('refund_status', Payment::REFUND_PENDING)
            ->update(['refund_status' => Payment::REFUND_MANUAL]);

        if ($moved > 0) {
            Log::warning('A GCash refund could not go through Xendit; it is now a refund by hand.', [
                'payment_id' => $payment->getKey(),
                'order_id' => $payment->order_id,
                'why' => $why,
            ]);
        }

        $payment->refresh();
    }

    /** A refund this payment already has at Xendit under our reference, if any. */
    private function sentBefore(Payment $payment, string $paymentId): ?GatewayRefund
    {
        try {
            $refunds = $this->gateway->refundsFor($paymentId);
        } catch (PaymentGatewayException $e) {
            // Nothing there to list (or listing refused): nothing was sent before.
            if ($e->status !== null && $e->status >= 400 && $e->status < 500) {
                return null;
            }

            throw $e;
        }

        foreach ($refunds as $refund) {
            if ($refund->reference === $payment->refundReference()) {
                return $refund;
            }
        }

        return null;
    }

    /** The method was not read back when the payment landed: ask again. */
    private function learnMethod(Payment $payment): void
    {
        if ($payment->provider_reference_id === null) {
            return;
        }

        $requestId = $this->gateway->getSession($payment->provider_reference_id)->paymentRequestId;
        $method = $requestId === null ? null : $this->gateway->paymentChannel($requestId);

        if ($method !== null) {
            $payment->forceFill(['payment_method' => $method])->save();
        }
    }
}
