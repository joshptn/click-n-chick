<?php

namespace App\Services\Verification;

use App\Models\User;

/**
 * A transport for verification codes.
 *
 * Everything about generating, storing, superseding, expiring, counting
 * attempts against and consuming a code lives in OtpService and is shared. An
 * implementation of this interface answers only the four things that genuinely
 * differ per channel: which identifier it addresses, how that identifier is
 * canonicalised, how to find the account behind one, and how to deliver.
 *
 * Adding a third channel should mean adding one class here and nothing else.
 */
interface VerificationChannel
{
    public function channel(): Channel;

    /** The raw identifier this channel would address on the given account. */
    public function identifierFor(User $user): ?string;

    /**
     * Canonical form used for hashing and lookup, or null when the input is not
     * a valid identifier for this channel. Callers must treat null as "no
     * match" rather than "match anything".
     */
    public function normalize(?string $raw): ?string;

    /** The account this identifier belongs to, if any. */
    public function findUser(string $identifier): ?User;

    /** Partially masked identifier, safe to echo back to an unauthenticated caller. */
    public function mask(?string $identifier): ?string;

    /** Record that this channel has now been verified for the account. */
    public function markVerified(User $user): void;

    /**
     * Can this channel actually deliver right now?
     *
     * Derived from configuration, never hardcoded, so the UI flips the moment
     * the provider situation changes without a follow-up frontend edit.
     */
    public function isAvailable(): bool;

    /** User-facing reason the channel is unavailable, or null when it is. */
    public function unavailableReason(): ?string;

    /** Short human label for the choice UI. */
    public function label(): string;

    /**
     * Deliver the code.
     *
     * @throws \RuntimeException when the transport refused the message.
     */
    public function send(string $identifier, string $code): void;
}
