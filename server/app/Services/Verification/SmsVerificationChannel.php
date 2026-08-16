<?php

namespace App\Services\Verification;

use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Sms\SmsSender;

class SmsVerificationChannel implements VerificationChannel
{
    public function __construct(private SmsSender $sms)
    {
    }

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function identifierFor(User $user): ?string
    {
        return $user->phone_number;
    }

    public function normalize(?string $raw): ?string
    {
        return User::normalizePhoneNumber($raw);
    }

    public function findUser(string $identifier): ?User
    {
        $hash = User::hashPhoneNumber($identifier);

        // Guard the null case explicitly: where('col', null) compiles to IS NULL
        // and would match every phone-less account.
        return $hash === null
            ? null
            : User::where('phone_number_hash', $hash)->first();
    }

    /** '+639171234567' -> '+639*****567'. */
    public function mask(?string $identifier): ?string
    {
        if ($identifier === null || strlen($identifier) < 7) {
            return $identifier;
        }

        return substr($identifier, 0, 4).str_repeat('*', strlen($identifier) - 7).substr($identifier, -3);
    }

    public function markVerified(User $user): void
    {
        $user->phone_verified_at = now();
    }

    /**
     * Available only when a real provider is configured.
     *
     * SMS_DRIVER=log writes the code to the application log and dispatches
     * nothing, so offering it to a user would be a dead end. Setting
     * SMS_DRIVER=semaphore with a key present turns this - and the phone option
     * in every channel picker - back on with no code or UI change.
     */
    public function isAvailable(): bool
    {
        return config('services.sms.driver') === 'semaphore'
            && filled(config('services.semaphore.key'));
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable()
            ? null
            : 'SMS delivery is not available yet. Please use email instead.';
    }

    public function label(): string
    {
        return 'Text message';
    }

    /**
     * The message carries the literal '{otp}' placeholder and the code travels
     * separately - see SmsSender::send(). That contract is unchanged here.
     */
    public function send(string $identifier, string $code): void
    {
        $this->sms->send($identifier, OtpService::messageTemplate(), $code);
    }
}
