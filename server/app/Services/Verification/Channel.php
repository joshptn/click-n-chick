<?php

namespace App\Services\Verification;

/**
 * The verification channels a user may choose at registration (PRD §6.2).
 *
 * Both are first-class; neither is a fallback for the other. The value is what
 * is stored in users.verification_channel and otp_codes.channel.
 */
enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * Infer the channel from the shape of an identifier, so callers may submit
     * either an address or a number without also naming the channel.
     */
    public static function forIdentifier(?string $identifier): ?self
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        return filter_var(trim($identifier), FILTER_VALIDATE_EMAIL) ? self::Email : self::Sms;
    }
}
