<?php

namespace App\Services\Otp;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class OtpService
{
    /** Digits in a generated code. */
    public const CODE_LENGTH = 6;

    /** How long a freshly issued code stays valid. */
    public const EXPIRY_MINUTES = 10;

    /** Wrong guesses allowed against a single code before it is burned. */
    public const MAX_ATTEMPTS = 5;

    /** Minimum gap between two sends to the same number. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private SmsSender $sms)
    {
    }

    /**
     * Issue a code for the given purpose and text it to the user.
     *
     * Any earlier unconsumed code for the same number+purpose is invalidated
     * first, so only the most recent code can ever be redeemed.
     */
    public function send(User $user, string $purpose, ?string $ip = null): OtpCode
    {
        $phoneHash = $user->phone_number_hash;

        $code = $this->generateCode();

        $otp = DB::transaction(function () use ($user, $phoneHash, $purpose, $ip, $code) {
            // Supersede outstanding codes: marking them consumed keeps the row for
            // audit while making them unredeemable.
            OtpCode::query()
                ->where('phone_number_hash', $phoneHash)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return OtpCode::create([
                'user_id' => $user->id,
                'phone_number_hash' => $phoneHash,
                'code_hash' => Hash::make($code),
                'purpose' => $purpose,
                'ip_address' => $ip,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);
        });

        // Deliberately link-free: Smart blocks shortened URLs at the telco level.
        $this->sms->send(
            $user->phone_number,
            "{$code} is your Click n Chick verification code. It expires in "
                .self::EXPIRY_MINUTES.' minutes. Do not share it with anyone.'
        );

        return $otp;
    }

    /**
     * True when the most recent send for this number+purpose is still inside
     * the cooldown, i.e. a resend should be refused.
     */
    public function secondsUntilResendAllowed(?string $phoneHash, string $purpose): int
    {
        if ($phoneHash === null) {
            return 0;
        }

        $last = OtpCode::query()
            ->where('phone_number_hash', $phoneHash)
            ->where('purpose', $purpose)
            ->latest('created_at')
            ->first();

        if (! $last) {
            return 0;
        }

        $elapsed = $last->created_at->diffInSeconds(now());

        return (int) max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * Redeem a code. Returns the outcome so callers can respond precisely
     * without leaking which accounts exist.
     */
    public function verify(?string $phoneHash, string $purpose, string $code): OtpVerificationResult
    {
        if ($phoneHash === null) {
            return OtpVerificationResult::NoCode;
        }

        $otp = OtpCode::query()
            ->where('phone_number_hash', $phoneHash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            return OtpVerificationResult::NoCode;
        }

        if ($otp->expires_at !== null && $otp->expires_at->isPast()) {
            return OtpVerificationResult::Expired;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            // Burn it so it cannot be ground down further.
            $otp->forceFill(['consumed_at' => now()])->save();

            return OtpVerificationResult::TooManyAttempts;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return OtpVerificationResult::Invalid;
        }

        // Single use: consumed the moment it succeeds.
        $otp->forceFill(['consumed_at' => now()])->save();

        return OtpVerificationResult::Verified;
    }

    /**
     * Zero-padded numeric code. random_int is the CSPRNG - rand()/mt_rand()
     * would be predictable enough to matter for a 6-digit secret.
     */
    private function generateCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;

        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
