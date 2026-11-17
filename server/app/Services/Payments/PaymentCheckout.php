<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Opens the hosted checkout for an order awaiting payment (FR-04.2).
 *
 * One payment row per checkout. While a checkout is still open the customer
 * is sent back to it, so retrying a failed GCash attempt or rescanning the QR
 * code happens on the same session rather than opening a second one that
 * could take the money twice.
 */
class PaymentCheckout
{
    /** How long an advance order's checkout stays open; its own deadline is a day. */
    private const ADVANCE_SESSION_MINUTES = 15;

    public function __construct(
        private PaymentGateway $gateway,
        private PaymentSettlement $settlement,
    ) {}

    public static function awaitsPayment(Order $order): bool
    {
        return ! $order->isPaid()
            && in_array((string) $order->status, [OrderStatus::AWAITING_PAYMENT, OrderStatus::ACCEPTED], true);
    }

    /**
     * The checkout to send the customer to. A session that turns out to have
     * been paid already is settled and returned as completed.
     *
     * @throws PaymentGatewayException
     */
    public function open(Order $order): GatewaySession
    {
        $open = $this->reusable($order);

        if ($open !== null) {
            return $open;
        }

        $payment = Payment::create([
            'order_id' => $order->getKey(),
            'user_id' => $order->user_id,
            'payment_status' => Payment::STATUS_PENDING,
        ]);

        try {
            $session = $this->gateway->createSession(
                $payment->gatewayReference(),
                (float) ($order->total_amount ?? $order->total_price),
                'Click n Chick order #'.$order->getKey(),
                $this->expiresAt($order),
                ['order_id' => (string) $order->getKey(), 'payment_id' => (string) $payment->getKey()],
            );
        } catch (PaymentGatewayException $e) {
            $payment->forceFill(['payment_status' => Payment::STATUS_CLOSED])->save();

            throw $e;
        }

        $payment->forceFill(['provider_reference_id' => $session->id])->save();

        return $session;
    }

    /**
     * Stop every open checkout of an order from taking money. Best effort: an
     * unreachable provider is logged, and a payment that still lands later is
     * handled by PaymentSettlement. Returns true if one had in fact been paid.
     */
    public function closeOpen(Order $order): bool
    {
        $paid = false;

        foreach ($order->payments()->pending()->whereNotNull('provider_reference_id')->get() as $payment) {
            try {
                $session = $this->gateway->cancelSession($payment->provider_reference_id);
                $paid = $this->settlement->apply($payment, $session)->payment_status === Payment::STATUS_PAID || $paid;
            } catch (PaymentGatewayException $e) {
                Log::warning('A checkout could not be closed with the payment provider.', [
                    'order_id' => $order->getKey(),
                    'payment_id' => $payment->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $paid;
    }

    private function reusable(Order $order): ?GatewaySession
    {
        $pending = $order->payments()->pending()->whereNotNull('provider_reference_id')->latest('id')->get();

        foreach ($pending as $payment) {
            try {
                $session = $this->gateway->getSession($payment->provider_reference_id);
            } catch (PaymentGatewayException) {
                continue;
            }

            if ($session->isCompleted()) {
                $this->settlement->apply($payment, $session);

                return $session;
            }

            if ($session->isUsable()) {
                return $session;
            }

            // About to lapse, or already closed: close it so it cannot be paid
            // alongside the new one.
            if ($session->status === GatewaySession::ACTIVE) {
                try {
                    $session = $this->gateway->cancelSession($session->id);
                } catch (PaymentGatewayException) {
                    // A late payment on it is still handled by the settlement.
                }
            }

            $this->settlement->apply($payment, $session);

            if ($session->isCompleted()) {
                return $session;
            }
        }

        return null;
    }

    private function expiresAt(Order $order): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if (! $order->isAdvance()) {
            return $order->paymentDueAt() ?? $now->addMinutes(Order::PAYMENT_WINDOW_MINUTES);
        }

        $session = $now->addMinutes(self::ADVANCE_SESSION_MINUTES);
        $due = $order->paymentDueAt();

        return $due !== null && $due->lessThan($session) ? $due : $session;
    }
}
