<?php

namespace App\Services\Verification;

use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Sms\SmsSender;

class SmsVerificationChannel implements VerificationChannel
{
    public function __construct(private SmsSender $sms) {}

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
     * Available when a real provider is configured, or on a local machine.
     *
     * SMS_DRIVER=log writes the code to the application log and dispatches
     * nothing. Anywhere else that would be a dead end for the user, but locally
     * the developer reads the code from storage/logs/laravel.log, so phone
     * sign-up and 2FA work there without spending Semaphore credits. Tests run
     * as 'testing' and production as 'production', so neither is affected.
     * SMS_DRIVER=semaphore with a key present turns it on everywhere.
     */
    public function isAvailable(): bool
    {
        $driver = config('services.sms.driver');

        if ($driver === 'semaphore') {
            return filled(config('services.semaphore.key'));
        }

        return $driver === 'log' && app()->environment('local');
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
