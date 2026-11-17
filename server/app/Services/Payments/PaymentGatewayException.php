<?php

namespace App\Services\Payments;

use RuntimeException;

/** The provider could not be reached, or refused the call. */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
