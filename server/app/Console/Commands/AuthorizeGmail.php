<?php

namespace App\Console\Commands;

use App\Services\Mail\GmailOAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-time consent that produces GMAIL_REFRESH_TOKEN.
 *
 * Run locally with GMAIL_CLIENT_ID and GMAIL_CLIENT_SECRET in .env. Google
 * redirects the browser to the loopback address after consent; nothing listens
 * there, so the page fails to load, but the address bar holds the code. Pasting
 * that address back here exchanges it for the refresh token.
 */
class AuthorizeGmail extends Command
{
    protected $signature = 'mail:gmail-authorize
                            {--redirect=http://127.0.0.1 : Redirect URI registered on the OAuth client}';

    protected $description = 'Consent once with the sending Gmail account and print its refresh token';

    public function handle(): int
    {
        $oauth = GmailOAuth::fromConfig();

        if (! $oauth->hasClient()) {
            $this->error('GMAIL_CLIENT_ID or GMAIL_CLIENT_SECRET is empty. Add both to .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        $redirect = (string) $this->option('redirect');
        $state = Str::random(32);

        $this->line('1. Open this address and sign in with the Gmail account the app should send from:');
        $this->newLine();
        $this->line($oauth->authorizationUrl($redirect, $state));
        $this->newLine();
        $this->line("2. Allow access. The browser then lands on {$redirect} and shows a connection error - that is expected.");
        $this->line('3. Copy the whole address from the address bar and paste it below.');
        $this->newLine();

        $pasted = trim((string) $this->ask('Address (or just the code)'));

        $code = $this->codeFrom($pasted, $state);

        if ($code === null) {
            return self::FAILURE;
        }

        try {
            $granted = $oauth->exchangeCode($code, $redirect);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! str_contains($granted['scope'], GmailOAuth::SCOPE)) {
            $this->error('Google did not grant the gmail.send scope. Run this again and allow sending email.');

            return self::FAILURE;
        }

        if (blank($granted['refresh_token'])) {
            $this->error('Google returned no refresh token. Remove the app at https://myaccount.google.com/permissions and run this again.');

            return self::FAILURE;
        }

        $this->info('Authorized. Set this as GMAIL_REFRESH_TOKEN in .env and on Render:');
        $this->newLine();
        $this->line($granted['refresh_token']);
        $this->newLine();
        $this->warn('Treat it like a password. While the OAuth consent screen is in "Testing", Google expires it after 7 days.');

        return self::SUCCESS;
    }

    private function codeFrom(string $pasted, string $state): ?string
    {
        if (! str_contains($pasted, '?')) {
            if ($pasted === '') {
                $this->error('Nothing was pasted.');

                return null;
            }

            return $pasted;
        }

        parse_str((string) parse_url($pasted, PHP_URL_QUERY), $query);

        if (isset($query['error'])) {
            $this->error('Google returned: '.$query['error']);

            return null;
        }

        if (($query['state'] ?? null) !== $state) {
            $this->error('That address is from a different authorization attempt. Use the link printed above.');

            return null;
        }

        if (blank($query['code'] ?? null)) {
            $this->error('The address has no code in it.');

            return null;
        }

        return (string) $query['code'];
    }
}
