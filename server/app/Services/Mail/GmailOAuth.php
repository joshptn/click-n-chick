<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * OAuth for the Gmail API, on a refresh token held in the environment.
 *
 * A personal Gmail account cannot use a service account (that needs Workspace
 * domain-wide delegation), so the account owner consents once through
 * `mail:gmail-authorize` and the resulting refresh token is what production
 * holds. Access tokens last an hour and are cached, keyed on the credentials so
 * that rotating them never reuses a token minted for the old ones.
 *
 * Only `gmail.send` is requested: the app can send as the account and can read
 * nothing in it.
 */
class GmailOAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private ?string $clientId,
        private ?string $clientSecret,
        private ?string $refreshToken,
        private int $timeoutSeconds = 10,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.gmail.client_id'),
            config('services.gmail.client_secret'),
            config('services.gmail.refresh_token'),
            (int) config('services.gmail.timeout', 10),
        );
    }

    public function hasClient(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret);
    }

    public function isConfigured(): bool
    {
        return $this->hasClient() && filled($this->refreshToken);
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            // Offline access is what returns a refresh token; prompt=consent makes
            // Google issue a new one even when this account consented before.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** @return array{refresh_token: ?string, scope: string} */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $body = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        return [
            'refresh_token' => $body['refresh_token'] ?? null,
            'scope' => (string) ($body['scope'] ?? ''),
        ];
    }

    public function accessToken(bool $fresh = false): string
    {
        $key = $this->cacheKey();

        if (! $fresh && is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $body = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);

        $token = $body['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new TransportException('Google returned no access token for the Gmail refresh token.');
        }

        // A minute short of the real expiry, so a token is never sent as it lapses.
        Cache::put($key, $token, max(60, (int) ($body['expires_in'] ?? 3600) - 60));

        return $token;
    }

    /** @param  array<string, string|null>  $grant */
    private function requestToken(array $grant): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(5)
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    ...$grant,
                ]);
        } catch (ConnectionException $e) {
            throw new TransportException('Could not reach Google to authorize the Gmail send: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new TransportException('Google refused the Gmail credentials (HTTP '.$response->status().'): '.$this->errorText($response));
        }

        return (array) $response->json();
    }

    /** Google's error and description fields; neither carries a credential. */
    private function errorText(Response $response): string
    {
        $error = $response->json('error');
        $description = $response->json('error_description');

        if (is_string($error)) {
            return $error.(is_string($description) ? ' - '.$description : '');
        }

        return 'no parsable error detail';
    }

    private function cacheKey(): string
    {
        return 'gmail:access-token:'.hash('sha256', $this->clientId.'|'.$this->refreshToken);
    }
}
