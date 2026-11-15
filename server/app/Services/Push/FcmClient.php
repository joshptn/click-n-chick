<?php

namespace App\Services\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1, on a service account key.
 *
 * The key signs a short JWT that Google exchanges for an hour-long access
 * token, cached a minute short of its expiry and keyed on the account, so a
 * rotated key never reuses a token minted for the old one. Done with openssl
 * rather than Google's SDK: it is one signature, not worth a dependency tree.
 *
 * HTTP v1 has no multicast, so each device is one request; they go out a batch
 * at a time, concurrently.
 */
class FcmClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const BATCH = 25;

    /** @param  array<string, mixed>|null  $account  the decoded service account key */
    public function __construct(
        private ?array $account,
        private ?string $projectId,
        private int $timeoutSeconds = 10,
    ) {}

    public static function fromConfig(): self
    {
        $account = self::decodeCredentials(config('services.firebase.credentials'));

        return new self(
            $account,
            filled(config('services.firebase.project_id'))
                ? (string) config('services.firebase.project_id')
                : ($account['project_id'] ?? null),
            (int) config('services.firebase.timeout', 10),
        );
    }

    /** The key as JSON, or as base64 of that JSON. Anything else counts as unset. */
    public static function decodeCredentials(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $json = str_starts_with($raw, '{') ? $raw : base64_decode($raw, true);
        $account = is_string($json) ? json_decode($json, true) : null;

        return is_array($account) ? $account : null;
    }

    public function isConfigured(): bool
    {
        return filled($this->projectId)
            && filled($this->account['client_email'] ?? null)
            && filled($this->account['private_key'] ?? null);
    }

    /**
     * Send one message to each token.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     */
    public function send(array $tokens, string $title, string $body, array $data = []): PushResult
    {
        $result = new PushResult;

        if ($tokens === []) {
            return $result;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Firebase is not configured (FIREBASE_CREDENTIALS).');
        }

        $accessToken = $this->accessToken();
        $url = sprintf(self::SEND_URL, $this->projectId);

        foreach (array_chunk(array_values(array_unique($tokens)), self::BATCH) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $token) => $pool->as($token)
                    ->withToken($accessToken)
                    ->acceptJson()
                    ->timeout($this->timeoutSeconds)
                    ->connectTimeout(5)
                    ->post($url, ['message' => $this->message($token, $title, $body, $data)]),
                $batch
            ));

            foreach ($batch as $token) {
                $this->record($result, $token, $responses[$token] ?? null);
            }
        }

        return $result;
    }

    /** @param  array<string, string>  $data */
    private function message(string $token, string $title, string $body, array $data): array
    {
        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            // Delivered at once rather than batched for battery, on both.
            'android' => ['priority' => 'high'],
            'webpush' => ['headers' => ['Urgency' => 'high']],
        ];

        if ($data !== []) {
            // FCM accepts string values only.
            $message['data'] = array_map('strval', $data);
        }

        return $message;
    }

    private function record(PushResult $result, string $token, mixed $response): void
    {
        if (! $response instanceof Response) {
            $result->failed[$token] = $response instanceof \Throwable ? $response->getMessage() : 'no response';

            return;
        }

        if ($response->successful()) {
            $result->sent++;

            return;
        }

        if ($this->tokenIsDead($response)) {
            $result->invalid[] = $token;

            return;
        }

        $result->failed[$token] = 'HTTP '.$response->status().': '.(string) ($response->json('error.message') ?? 'no detail');
    }

    /**
     * Whether FCM says this token will never work again.
     *
     * UNREGISTERED (404): the app was uninstalled or the token expired.
     * SENDER_ID_MISMATCH (403): it belongs to another Firebase project.
     * INVALID_ARGUMENT (400) only when the complaint is about the token: the same
     * code also means a malformed message, which must not delete every token.
     */
    private function tokenIsDead(Response $response): bool
    {
        $codes = collect((array) $response->json('error.details', []))
            ->pluck('errorCode')
            ->filter()
            ->all();

        if (in_array('UNREGISTERED', $codes, true) || in_array('SENDER_ID_MISMATCH', $codes, true)) {
            return true;
        }

        // A bare 404 is not enough: a wrong project id answers 404 for every
        // token, and that must not wipe them all.
        return $response->status() === 400
            && str_contains(strtolower((string) $response->json('error.message', '')), 'registration token');
    }

    private function accessToken(): string
    {
        $key = 'fcm:access-token:'.hash('sha256', $this->account['client_email'].'|'.($this->account['private_key_id'] ?? ''));

        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $tokenUri = (string) ($this->account['token_uri'] ?? self::DEFAULT_TOKEN_URI);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(5)
                ->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->assertion($tokenUri),
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Google to authorize FCM: '.$e->getMessage(), 0, $e);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            // Google's error code and description; neither carries a credential.
            throw new RuntimeException('Google refused the Firebase service account (HTTP '.$response->status().'): '
                .(string) ($response->json('error') ?? 'no detail'));
        }

        Cache::put($key, $token, max(60, (int) ($response->json('expires_in') ?? 3600) - 60));

        return $token;
    }

    /** The signed JWT Google trades for an access token. */
    private function assertion(string $audience): string
    {
        $now = time();

        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode([
                'iss' => $this->account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $audience,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private((string) $this->account['private_key']);

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Firebase service account private key could not sign a request.');
        }

        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
