<?php

namespace App\Services\Auth;

use App\Services\Otp\OtpService;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * A sign-up that has not been verified yet.
 *
 * Nothing is written to `users` until the code comes back: the submission
 * waits in the cache under the identifier's hash, and verify() turns it into
 * an account. So no unverified identifier ever sits on an account row, a
 * repeat submission replaces a short-lived cache entry and nothing else, and
 * there is no abandoned account to purge.
 *
 * The password is stored already hashed. The entry outlives the code by a
 * little, so a resend inside the window has something to resend for; after
 * that the person simply signs up again.
 */
class PendingRegistration
{
    public const TTL_MINUTES = OtpService::EXPIRY_MINUTES * 3;

    private const PREFIX = 'registration:pending:';

    public function __construct(private ChannelRegistry $channels) {}

    /**
     * @param  array{first_name: string, last_name: string, identifier: string, channel: string, password: string}  $submission
     *                                                                                                                           `identifier` canonical, `password` hashed
     */
    public function put(array $submission): void
    {
        Cache::put($this->key($submission['channel'], $submission['identifier']), $submission, now()->addMinutes(self::TTL_MINUTES));
    }

    /** @return array{first_name: string, last_name: string, identifier: string, channel: string, password: string}|null */
    public function get(Channel|string $channel, string $identifier): ?array
    {
        $entry = Cache::get($this->key($channel, $identifier));

        return is_array($entry) ? $entry : null;
    }

    public function forget(Channel|string $channel, string $identifier): void
    {
        Cache::forget($this->key($channel, $identifier));
    }

    private function key(Channel|string $channel, string $identifier): string
    {
        return self::PREFIX.($this->channels->hash($channel, $identifier) ?? 'none');
    }
}
