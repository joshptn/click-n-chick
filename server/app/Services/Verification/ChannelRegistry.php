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

    /**
     * Every channel with its current deliverability, for the choice UI.
     *
     * The single source the frontend reads, so the phone option enables itself
     * as soon as the SMS configuration says it can deliver.
     *
     * @return array<int, array{channel: string, label: string, available: bool, reason: ?string}>
     */
    public function describe(): array
    {
        return array_values(array_map(static fn (VerificationChannel $transport) => [
            'channel' => $transport->channel()->value,
            'label' => $transport->label(),
            'available' => $transport->isAvailable(),
            'reason' => $transport->unavailableReason(),
        ], $this->channels));
    }

    public function isAvailable(Channel|string|null $channel): bool
    {
        return $this->for($channel)->isAvailable();
    }

    /**
     * The channel to use when a caller does not name one.
     *
     * Channel::Sms is still the default; it is skipped only when this
     * deployment cannot deliver on it, which is the one case where honouring
     * the default would hand back an account whose code goes nowhere. Returns
     * Sms when nothing can deliver, so the caller still gets a channel to
     * report as unavailable rather than a null to branch on.
     */
    public function defaultChannel(): Channel
    {
        if ($this->isAvailable(Channel::Sms)) {
            return Channel::Sms;
        }

        foreach ($this->channels as $transport) {
            if ($transport->isAvailable()) {
                return $transport->channel();
            }
        }

        return Channel::Sms;
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
