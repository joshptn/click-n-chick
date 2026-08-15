<?php

namespace App\Services\Otp;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
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

    /**
     * One SMS segment. Semaphore's OTP route bills 2 credits per 160 characters,
     * so spilling into a second segment doubles the cost of every send.
     */
    public const MAX_MESSAGE_LENGTH = 160;

    /**
     * The message as sent to the provider.
     *
     * '{otp}' stays literal: Semaphore substitutes it from the `code` parameter.
     * Interpolating it here would make Semaphore append a second code of its own.
     * Deliberately link-free - Smart blocks shortened URLs at the telco level.
     */
    public static function messageTemplate(): string
    {
        return '{otp} is your Click n Chick verification code. It expires in '
            .self::EXPIRY_MINUTES.' minutes. Do not share it with anyone.';
    }

    /** Worst-case rendered length, used to keep the send inside one segment. */
    public static function renderedMessageLength(): int
    {
        return strlen(str_replace('{otp}', str_repeat('0', self::CODE_LENGTH), self::messageTemplate()));
    }

    public function __construct(private ChannelRegistry $channels)
    {
    }

    /**
     * Issue a code for the given purpose and deliver it over the user's chosen
     * channel.
     *
     * Nothing below branches on which channel that is: the registry hands back
     * a transport, and every rule about superseding, expiry and single use is
     * applied identically either way. That is the whole point of the channel
     * abstraction - one code path, two deliveries.
     *
     * Any earlier unconsumed code for the same identifier+purpose is
     * invalidated first, so only the most recent code can ever be redeemed.
     */
    public function send(User $user, string $purpose, ?string $ip = null, Channel|string|null $channel = null): OtpCode
    {
        $transport = $channel === null
            ? $this->channels->forUser($user)
            : $this->channels->for($channel);

        $identifier = $transport->identifierFor($user);
        $identifierHash = $this->channels->hash($transport->channel(), $identifier);

        $code = $this->generateCode();

        $otp = DB::transaction(function () use ($user, $transport, $identifierHash, $purpose, $ip, $code) {
            // Supersede outstanding codes: marking them consumed keeps the row for
            // audit while making them unredeemable.
            OtpCode::query()
                ->where('identifier_hash', $identifierHash)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return OtpCode::create([
                'user_id' => $user->id,
                'channel' => $transport->channel()->value,
                'identifier_hash' => $identifierHash,
                'code_hash' => Hash::make($code),
                'purpose' => $purpose,
                'ip_address' => $ip,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);
        });

        $transport->send($identifier, $code);

        return $otp;
    }

    /**
     * True when the most recent send for this identifier+purpose is still
     * inside the cooldown, i.e. a resend should be refused.
     */
    public function secondsUntilResendAllowed(?string $identifierHash, string $purpose): int
    {
        if ($identifierHash === null) {
            return 0;
        }

        $last = OtpCode::query()
            ->where('identifier_hash', $identifierHash)
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
    public function verify(?string $identifierHash, string $purpose, string $code): OtpVerificationResult
    {
        if ($identifierHash === null) {
            return OtpVerificationResult::NoCode;
        }

        $otp = OtpCode::query()
            ->where('identifier_hash', $identifierHash)
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
