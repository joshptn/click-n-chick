<?php

namespace App\Services\Verification;

use App\Models\User;
use InvalidArgumentException;

/**
 * Resolves a Channel to the object that can address it.
 *
 * The single place that knows which transports exist, so OtpService and the
 * controllers never branch on channel themselves.
 */
class ChannelRegistry
{
    /** @param array<string, VerificationChannel> $channels */
    public function __construct(private array $channels)
    {
    }

    public function for(Channel|string|null $channel): VerificationChannel
    {
        $key = $channel instanceof Channel
            ? $channel->value
            : (string) ($channel ?? Channel::Sms->value);

        if (! isset($this->channels[$key])) {
            throw new InvalidArgumentException("No verification channel registered for '{$key}'.");
        }

        return $this->channels[$key];
    }

    /** The channel the account chose at registration. */
    public function forUser(User $user): VerificationChannel
    {
        return $this->for($user->verification_channel);
    }

    /** The channel implied by the shape of a submitted identifier. */
    public function forIdentifier(?string $identifier): ?VerificationChannel
    {
        $channel = Channel::forIdentifier($identifier);

        return $channel === null ? null : $this->for($channel);
    }

    /**
     * Blind index for an identifier on a given channel.
     *
     * Phone hashes stay byte-identical to User::hashPhoneNumber() so the
     * existing users.phone_number_hash column keeps working. A collision across
     * channels is not possible - a canonical '+63…' number and a canonical
     * email address are never the same string.
     */
    public function hash(Channel|string|null $channel, ?string $raw): ?string
    {
        $canonical = $this->for($channel)->normalize($raw);

        if ($canonical === null) {
            return null;
        }

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }
}
