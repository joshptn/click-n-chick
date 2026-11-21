<?php

namespace App\Console\Commands;

use App\Services\Recaptcha\RecaptchaService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live check against the real Google siteverify endpoint.
 *
 * Answers the question the faked test suite cannot: is the secret key in .env
 * actually valid, and does a request that fails reCAPTCHA get refused end to
 * end?
 *
 * Without --token it submits a deliberately invalid token. Google's error code
 * then distinguishes the two failures that look identical from the outside:
 *
 *   invalid-input-secret   -> the SECRET is wrong. Nothing will ever verify.
 *   invalid-input-response -> the secret was ACCEPTED and the token rejected.
 *
 * With --token (one taken from the browser) it verifies that token, which
 * proves the site key and the secret belong together. Neither key is printed.
 */
class TestRecaptchaVerify extends Command
{
    protected $signature = 'recaptcha:test-verify
                            {--token= : Verify a real token from the browser instead of a deliberately invalid one}';

    protected $description = 'Verify the reCAPTCHA secret against Google and confirm a failing request is refused';

    public function handle(RecaptchaService $recaptcha): int
    {
        $this->line('  RECAPTCHA_ENABLED    : '.(config('services.recaptcha.enabled') ? 'true' : 'false'));
        $this->line('  RECAPTCHA_SITE_KEY   : '.(filled(config('services.recaptcha.site_key')) ? 'set' : '(empty)'));
        $this->line('  RECAPTCHA_SECRET_KEY : '.(filled(config('services.recaptcha.secret_key')) ? 'set' : '(empty)'));
        $this->newLine();

        if (! $recaptcha->isEnabled()) {
            $this->error('reCAPTCHA is not fully configured, so every guarded route is currently passing traffic through unchecked.');
            $this->line('Set RECAPTCHA_SITE_KEY and RECAPTCHA_SECRET_KEY in .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        $usingRealToken = filled($this->option('token'));
        $token = $usingRealToken
            ? (string) $this->option('token')
            : 'deliberately-invalid-token-'.bin2hex(random_bytes(8));

        // -----------------------------------------------------------------
        // 1. Is the secret real?
        // -----------------------------------------------------------------
        $body = null;

        $this->components->task('Contacting Google siteverify', function () use (&$body, $recaptcha, $token) {
            try {
                $response = Http::asForm()
                    ->timeout((int) config('services.recaptcha.timeout', 5))
                    ->post($recaptcha->verifyUrl(), [
                        'secret' => (string) config('services.recaptcha.secret_key'),
                        'response' => $token,
                    ]);

                $body = $response->json();

                return $response->successful();
            } catch (Throwable $e) {
                $this->newLine();
                $this->error('Could not reach Google: '.$e->getMessage());

                return false;
            }
        });

        if (! is_array($body)) {
            $this->error('No usable response from Google. Check outbound network access to www.google.com.');

            return self::FAILURE;
        }

        $errors = (array) ($body['error-codes'] ?? []);

        if (in_array('invalid-input-secret', $errors, true) || in_array('missing-input-secret', $errors, true)) {
            $this->error('SECRET KEY REJECTED. Google does not recognise RECAPTCHA_SECRET_KEY.');
            $this->line('  - Confirm you copied the SECRET key, not the site key.');
            $this->line('  - Run php artisan config:clear after editing .env.');

            return self::FAILURE;
        }

        if ($usingRealToken) {
            if (($body['success'] ?? false) !== true) {
                $this->error('SECRET ACCEPTED, but Google rejected this token: '.(implode(', ', $errors) ?: '(no error code)'));
                $this->line('  - timeout-or-duplicate: the token is over two minutes old or was already used.');
                $this->line('  - invalid-input-response: the token came from another site key than this secret.');

                return self::FAILURE;
            }

            $this->info('SECRET ACCEPTED and this token verified.');
            $this->line('  hostname     : '.($body['hostname'] ?? '(none)'));
            $this->line('  challenge_ts : '.($body['challenge_ts'] ?? '(none)'));
        } else {
            $this->info('SECRET ACCEPTED. Google processed the request and rejected the token, which is correct.');
            $this->line('  error-codes : '.(implode(', ', $errors) ?: '(none)'));
        }

        // -----------------------------------------------------------------
        // 2. Does the application refuse a request that fails?
        // -----------------------------------------------------------------
        $this->newLine();
        $this->line('Checking that a failing request is actually refused...');

        $result = $recaptcha->verify('deliberately-invalid-token-'.bin2hex(random_bytes(8)));

        if ($result->passed) {
            $this->error('RecaptchaService ALLOWED an invalid token (reason: '.$result->reason.'). See the log for the cause.');

            return self::FAILURE;
        }

        $this->info('RecaptchaService refused it. reason: '.$result->reason);

        // The full stack, through the real middleware on a real guarded route.
        $request = Request::create(
            '/api/password/forgot',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['identifier' => 'preflight@example.test', 'recaptcha_token' => 'deliberately-invalid-token']),
        );

        $response = app()->handle($request);
        $payload = json_decode($response->getContent(), true);

        if ($response->getStatusCode() === 422 && ($payload['error_code'] ?? null) === 'RECAPTCHA_FAILED') {
            $this->info('POST /api/password/forgot refused it too: 422 RECAPTCHA_FAILED ('.($payload['reason'] ?? '?').').');
            $this->newLine();
            $this->info('reCAPTCHA is live and rejecting requests that do not pass.');

            return self::SUCCESS;
        }

        $this->error('The endpoint did NOT refuse an invalid token. Status '.$response->getStatusCode().'.');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));

        return self::FAILURE;
    }
}
