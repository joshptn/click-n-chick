<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/** A hosted checkout as the provider reports it. */
final class GatewaySession
{
    public const ACTIVE = 'ACTIVE';

    public const COMPLETED = 'COMPLETED';

    public const EXPIRED = 'EXPIRED';

    public const CANCELED = 'CANCELED';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $reference,
        public readonly float $amount,
        public readonly ?string $url,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $paymentId = null,
        public readonly ?string $paymentRequestId = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /** Still able to take money, with a minute to spare for the customer to use it. */
    public function isUsable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->status === self::ACTIVE
            && $this->url !== null
            && ($this->expiresAt === null || $this->expiresAt->greaterThan($now->addMinute()));
    }

    public static function fromXendit(array $body): self
    {
        $expires = $body['expires_at'] ?? null;

        return new self(
            id: (string) ($body['payment_session_id'] ?? ''),
            status: strtoupper((string) ($body['status'] ?? '')),
            reference: (string) ($body['reference_id'] ?? ''),
            amount: (float) ($body['amount'] ?? 0),
            url: $body['payment_link_url'] ?? null,
            expiresAt: $expires ? CarbonImmutable::parse($expires) : null,
            paymentId: $body['payment_id'] ?? null,
            paymentRequestId: $body['payment_request_id'] ?? $body['latest_payment_request_id'] ?? null,
        );
    }
}
