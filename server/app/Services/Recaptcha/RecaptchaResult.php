<?php

namespace App\Services\Recaptcha;

final class RecaptchaResult
{
    public const OK = 'ok';

    /** reCAPTCHA is switched off or not fully configured. */
    public const SKIPPED = 'skipped';

    /** No token: the browser never finished the check. */
    public const MISSING = 'missing';

    /** Google rejected the token. */
    public const INVALID = 'invalid';

    /** The token was over two minutes old or had already been used. */
    public const EXPIRED = 'expired';

    /** Google could not be reached; the request was let through. */
    public const UNREACHABLE = 'unreachable';

    public function __construct(
        public readonly bool $passed,
        public readonly string $reason,
    ) {}

    public static function pass(string $reason = self::OK): self
    {
        return new self(true, $reason);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
