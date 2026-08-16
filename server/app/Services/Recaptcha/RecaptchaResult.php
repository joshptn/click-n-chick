<?php

namespace App\Services\Recaptcha;

final class RecaptchaResult
{
    public const OK = 'ok';

    public const SKIPPED = 'skipped';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public const ACTION_MISMATCH = 'action_mismatch';

    public const LOW_SCORE = 'low_score';

    public const UNREACHABLE = 'unreachable';

    public function __construct(
        public readonly bool $passed,
        public readonly string $reason,
        public readonly ?float $score = null,
    ) {
    }

    public static function pass(string $reason = self::OK, ?float $score = null): self
    {
        return new self(true, $reason, $score);
    }

    public static function fail(string $reason, ?float $score = null): self
    {
        return new self(false, $reason, $score);
    }
}
