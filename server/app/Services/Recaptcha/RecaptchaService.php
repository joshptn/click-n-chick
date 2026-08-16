<?php

namespace App\Services\Recaptcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * reCAPTCHA v3 verification.
 *
 * v3 is scored rather than challenge-based: the browser produces a token
 * silently and the score, not a puzzle, decides. That means every guarded
 * endpoint needs its own action name so a token minted on the login form
 * cannot be replayed against the password-reset endpoint.
 */
class RecaptchaService
{
    /**
     * Fallback for a missing services.recaptcha.verify_url.
     *
     * Without one the verify call posts to an empty URL, which throws, which
     * the catch below treats as an outage and fails open - silently disabling
     * reCAPTCHA on every guarded route. A dropped config key must not be able
     * to turn the protection off, so the endpoint is hardcoded here as well.
     */
    private const DEFAULT_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function verifyUrl(): string
    {
        $configured = config('services.recaptcha.verify_url');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_VERIFY_URL;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.recaptcha.enabled')
            && filled(config('services.recaptcha.secret_key'))
            && filled(config('services.recaptcha.site_key'));
    }

    public function siteKey(): ?string
    {
        return $this->isEnabled() ? (string) config('services.recaptcha.site_key') : null;
    }

    public function minScore(): float
    {
        return (float) config('services.recaptcha.min_score', 0.5);
    }

    /**
     * Verify a token against Google, checking success, action and score.
     *
     * Skips entirely while unconfigured, which is what keeps every guarded
     * flow working before credentials exist.
     */
    public function verify(?string $token, string $action, ?string $ip = null): RecaptchaResult
    {
        if (! $this->isEnabled()) {
            return RecaptchaResult::pass(RecaptchaResult::SKIPPED);
        }

        if (! is_string($token) || trim($token) === '') {
            return RecaptchaResult::fail(RecaptchaResult::MISSING);
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.recaptcha.timeout', 5))
                ->post($this->verifyUrl(), array_filter([
                    'secret' => (string) config('services.recaptcha.secret_key'),
                    'response' => trim($token),
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $e) {
            // Fail open. A Google outage or an egress block must not take
            // registration and sign-in down with it - the rate limiters remain
            // in force either way. Logged so the gap is visible rather than
            // silent.
            Log::warning('reCAPTCHA verification unreachable; allowing the request.', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return RecaptchaResult::pass(RecaptchaResult::UNREACHABLE);
        }

        if (! $response->successful()) {
            Log::warning('reCAPTCHA verification returned a non-2xx response; allowing the request.', [
                'action' => $action,
                'status' => $response->status(),
            ]);

            return RecaptchaResult::pass(RecaptchaResult::UNREACHABLE);
        }

        $body = $response->json();

        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            return RecaptchaResult::fail(RecaptchaResult::INVALID);
        }

        // v3 only. A v2 token verifies without an action, which would otherwise
        // sail through this check.
        if (($body['action'] ?? null) !== $action) {
            return RecaptchaResult::fail(RecaptchaResult::ACTION_MISMATCH);
        }

        $score = isset($body['score']) ? (float) $body['score'] : null;

        if ($score === null || $score < $this->minScore()) {
            return RecaptchaResult::fail(RecaptchaResult::LOW_SCORE, $score);
        }

        return RecaptchaResult::pass(RecaptchaResult::OK, $score);
    }
}
