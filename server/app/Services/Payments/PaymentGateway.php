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
     * Open a hosted checkout for one payment.
     *
     * @throws PaymentGatewayException
     */
    public function createSession(
        string $reference,
        float $amount,
        string $description,
        CarbonImmutable $expiresAt,
        array $metadata = [],
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
}
