<?php

namespace App\Services\Recaptcha;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * reCAPTCHA v2 Invisible verification (decided 2026-11-22).
 *
 * Google decides in the browser: most people pass without seeing anything, and
 * anyone it is unsure about gets its image puzzle there. A token only exists
 * once the person has passed, so the verdict here is pass or fail - there is no
 * score, no action name and no low-score handling of our own.
 *
 * The site address in the verdict is not checked here: the key's domain list in
 * Google's console does that (decided 2026-11-22).
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

    /** Google's error for a token that is over two minutes old or was already used. */
    private const EXPIRED_ERROR = 'timeout-or-duplicate';

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

    /**
     * Verify a token against Google.
     *
     * Skips entirely while unconfigured, which is what keeps every guarded
     * flow working before credentials exist.
     */
    public function verify(?string $token, ?string $ip = null): RecaptchaResult
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
                // One more try when the connection itself failed (a reset or a
                // refused handshake, seen on flaky networks), before failing
                // open below. Never on an answer: a token is single-use.
                ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->post($this->verifyUrl(), array_filter([
                    'secret' => (string) config('services.recaptcha.secret_key'),
                    'response' => trim($token),
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $e) {
            // Fail open (kept 2026-11-22). A Google outage or an egress block
            // must not take sign-up and sign-in down with it - the rate limiters
            // remain in force either way. Logged so the gap is visible.
            Log::warning('reCAPTCHA verification unreachable; allowing the request.', [
                'error' => $e->getMessage(),
            ]);

            return RecaptchaResult::pass(RecaptchaResult::UNREACHABLE);
        }

        if (! $response->successful()) {
            Log::warning('reCAPTCHA verification returned a non-2xx response; allowing the request.', [
                'status' => $response->status(),
            ]);

            return RecaptchaResult::pass(RecaptchaResult::UNREACHABLE);
        }

        $body = $response->json();

        if (is_array($body) && ($body['success'] ?? false) === true) {
            return RecaptchaResult::pass();
        }

        $errors = is_array($body) ? (array) ($body['error-codes'] ?? []) : [];

        return RecaptchaResult::fail(
            in_array(self::EXPIRED_ERROR, $errors, true) ? RecaptchaResult::EXPIRED : RecaptchaResult::INVALID
        );
    }
}
