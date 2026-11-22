<?php

namespace App\Services\Payments;

/** A refund as the provider reports it (Xendit Payments API v3, /v3/refunds). */
final class GatewayRefund
{
    public const PENDING = 'PENDING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $reference,
        public readonly ?string $paymentId = null,
        public readonly ?string $failureCode = null,
    ) {}

    public static function fromXendit(array $body): self
    {
        return new self(
            id: (string) ($body['refund_id'] ?? $body['id'] ?? ''),
            status: strtoupper((string) ($body['status'] ?? '')),
            reference: (string) ($body['reference_id'] ?? ''),
            paymentId: $body['payment_id'] ?? null,
            failureCode: $body['failure_code'] ?? null,
        );
    }
}
