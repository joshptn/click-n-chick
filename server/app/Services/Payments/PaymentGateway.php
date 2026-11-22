<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * The payment provider, as the rest of the app sees it (FR-04).
 *
 * One implementation talks to Xendit; the test suite gets an in-memory one
 * (AppServiceProvider), so no test reaches the provider.
 */
interface PaymentGateway
{
    /**
     * Open a hosted checkout for one payment. With $toApp the customer is
     * returned to the page that sends them back to the native app.
     *
     * @throws PaymentGatewayException
     */
    public function createSession(
        string $reference,
        float $amount,
        string $description,
        CarbonImmutable $expiresAt,
        array $metadata = [],
        bool $toApp = false,
    ): GatewaySession;

    /** @throws PaymentGatewayException */
    public function getSession(string $sessionId): GatewaySession;

    /**
     * Stop a checkout from taking money. Returns the session as it stands
     * afterwards: already COMPLETED if the customer paid first.
     *
     * @throws PaymentGatewayException
     */
    public function cancelSession(string $sessionId): GatewaySession;

    /**
     * Which method paid a payment request (QRPH, GCASH), or null if unknown.
     *
     * @throws PaymentGatewayException
     */
    public function paymentChannel(string $paymentRequestId): ?string;

    /**
     * Refund a payment in full (decided 2026-11-23: GCash only; Xendit cannot
     * refund QR Ph). $reference names the refund on our side, one per payment.
     *
     * @throws PaymentGatewayException
     */
    public function refund(string $paymentId, float $amount, string $reference, string $reason): GatewayRefund;

    /** @throws PaymentGatewayException */
    public function getRefund(string $refundId): GatewayRefund;

    /**
     * The refunds already made on a payment, so a retried refund can find the
     * one it sent before rather than sending a second.
     *
     * @return list<GatewayRefund>
     *
     * @throws PaymentGatewayException
     */
    public function refundsFor(string $paymentId): array;

    /**
     * The payer as the payment method provider reported them. Xendit fills
     * these only when the provider gives them, so any may be null.
     *
     * @return array{name: ?string, account: ?string, issuer: ?string, receipt: ?string}
     *
     * @throws PaymentGatewayException
     */
    public function payerDetails(string $paymentId): array;
}
