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

    private int $counter = 0;

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
