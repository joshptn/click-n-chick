<?php

namespace App\Services\Verification;

use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class EmailVerificationChannel implements VerificationChannel
{
    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function identifierFor(User $user): ?string
    {
        return $user->email;
    }

    /**
     * Lowercased and trimmed so the same address always hashes identically.
     * Anything that is not a valid address canonicalises to null.
     */
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = mb_strtolower(trim($raw));

        return filter_var($trimmed, FILTER_VALIDATE_EMAIL) ? $trimmed : null;
    }

    public function findUser(string $identifier): ?User
    {
        $normalized = $this->normalize($identifier);

        return $normalized === null
            ? null
            : User::whereRaw('LOWER(email) = ?', [$normalized])->first();
    }

    /** 'juan.delacruz@example.com' -> 'ju***@example.com'. */
    public function mask(?string $identifier): ?string
    {
        if ($identifier === null || ! str_contains($identifier, '@')) {
            return $identifier;
        }

        [$local, $domain] = explode('@', $identifier, 2);

        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    public function markVerified(User $user): void
    {
        $user->email_verified_at = now();
    }

    public function send(string $identifier, string $code): void
    {
        // Sent synchronously: registration blocks on this code arriving, so a
        // queue would add a failure mode with nothing watching it. There is no
        // queue worker configured for this app yet.
        Mail::to($identifier)->send(new VerificationCodeMail($code));
    }
}
