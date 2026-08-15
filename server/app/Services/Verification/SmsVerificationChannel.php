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
     * The message carries the literal '{otp}' placeholder and the code travels
     * separately - see SmsSender::send(). That contract is unchanged here.
     */
    public function send(string $identifier, string $code): void
    {
        $this->sms->send($identifier, OtpService::messageTemplate(), $code);
    }
}
