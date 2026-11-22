<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * The provider in memory, bound while the test suite runs (AppServiceProvider),
 * so no test can reach Xendit or spend a test-mode call.
 *
 * Tests drive it the way a customer would: pay() completes a session, as
 * Xendit does when the customer finishes on the hosted page.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, GatewaySession> */
    public array $sessions = [];

    /** @var array<string, string> payment request id => channel */
    public array $channels = [];

    /** @var list<string> */
    public array $cancelled = [];

    public bool $unreachable = false;

    /** @var array<string, bool> session id => returns to the native app */
    public array $returnsToApp = [];

    /** @var array<string, GatewayRefund> refund id => refund */
    public array $refunds = [];

    /** @var array<string, string> refund id => payment id */
    public array $refundedPayments = [];

    /** What a new refund comes back as: PENDING (as Xendit usually answers), SUCCEEDED or FAILED. */
    public string $refundStatus = GatewayRefund::PENDING;

    /** Xendit refuses the refund outright (QR Ph, or a channel error). */
    public bool $refuseRefunds = false;

    /** @var array<string, array{name: ?string, account: ?string, issuer: ?string, receipt: ?string}> */
    public array $payers = [];

    private int $counter = 0;

    private int $refundCounter = 0;

    public function createSession(
        string $reference,
        float $amount,
        string $description,
        CarbonImmutable $expiresAt,
        array $metadata = [],
        bool $toApp = false,
    ): GatewaySession {
        $this->guard();

        $id = 'ps-fake-'.(++$this->counter);
        $this->returnsToApp[$id] = $toApp;

        return $this->sessions[$id] = new GatewaySession(
            id: $id,
            status: GatewaySession::ACTIVE,
            reference: $reference,
            amount: round($amount, 2),
            url: 'https://checkout.example.test/'.$id,
            expiresAt: $expiresAt,
        );
    }

    public function getSession(string $sessionId): GatewaySession
    {
        $this->guard();

        return $this->sessions[$sessionId]
            ?? throw new PaymentGatewayException('Session not found.', 404, 'DATA_NOT_FOUND');
    }

    public function cancelSession(string $sessionId): GatewaySession
    {
        $session = $this->getSession($sessionId);
        $this->cancelled[] = $sessionId;

        if ($session->status !== GatewaySession::ACTIVE) {
            return $session;
        }

        return $this->sessions[$sessionId] = $this->with($session, GatewaySession::CANCELED);
    }

    public function paymentChannel(string $paymentRequestId): ?string
    {
        $this->guard();

        return $this->channels[$paymentRequestId] ?? null;
    }

    /** The customer pays on the hosted page. Returns the completed session. */
    public function pay(string $sessionId, string $channel = 'GCASH'): GatewaySession
    {
        $session = $this->sessions[$sessionId];
        $suffix = substr($sessionId, strlen('ps-fake-'));
        $requestId = 'pr-fake-'.$suffix;

        $this->channels[$requestId] = $channel;

        return $this->sessions[$sessionId] = $this->with(
            $session,
            GatewaySession::COMPLETED,
            'py-fake-'.$suffix,
            $requestId,
        );
    }

    public function latest(): ?GatewaySession
    {
        return $this->sessions === [] ? null : end($this->sessions);
    }

    public function refund(string $paymentId, float $amount, string $reference, string $reason): GatewayRefund
    {
        $this->guard();

        if ($this->refuseRefunds) {
            throw new PaymentGatewayException('Xendit refused the request: Refund feature is not available for this payment channel.', 400, 'REFUND_NOT_SUPPORTED');
        }

        $id = 'rfd-fake-'.(++$this->refundCounter);
        $this->refundedPayments[$id] = $paymentId;

        return $this->refunds[$id] = new GatewayRefund($id, $this->refundStatus, $reference, $paymentId);
    }

    public function getRefund(string $refundId): GatewayRefund
    {
        $this->guard();

        return $this->refunds[$refundId]
            ?? throw new PaymentGatewayException('Refund not found.', 404, 'DATA_NOT_FOUND');
    }

    public function refundsFor(string $paymentId): array
    {
        $this->guard();

        return array_values(array_filter(
            $this->refunds,
            fn (GatewayRefund $refund) => $refund->paymentId === $paymentId,
        ));
    }

    public function payerDetails(string $paymentId): array
    {
        $this->guard();

        return $this->payers[$paymentId] ?? ['name' => null, 'account' => null, 'issuer' => null, 'receipt' => null];
    }

    /** Xendit finishes a refund: what its webhook would report, and what getRefund() then says. */
    public function settleRefund(string $refundId, string $status = GatewayRefund::SUCCEEDED): GatewayRefund
    {
        $refund = $this->refunds[$refundId];

        return $this->refunds[$refundId] = new GatewayRefund($refund->id, $status, $refund->reference, $refund->paymentId);
    }

    private function with(GatewaySession $session, string $status, ?string $paymentId = null, ?string $requestId = null): GatewaySession
    {
        return new GatewaySession(
            id: $session->id,
            status: $status,
            reference: $session->reference,
            amount: $session->amount,
            url: $session->url,
            expiresAt: $session->expiresAt,
            paymentId: $paymentId ?? $session->paymentId,
            paymentRequestId: $requestId ?? $session->paymentRequestId,
        );
    }

    private function guard(): void
    {
        if ($this->unreachable) {
            throw new PaymentGatewayException('Xendit could not be reached: simulated outage.');
        }
    }
}
