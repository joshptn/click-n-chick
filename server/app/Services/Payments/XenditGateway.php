<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Xendit's hosted checkout (Payment Sessions) and Payments API v3.
 *
 * Checked against Xendit's API reference and a test-mode account (2026-11-17):
 * a session takes allowed_payment_channels QRPH and GCASH, must expire at least
 * ten minutes after it is created, and must return to an HTTPS address.
 */
class XenditGateway implements PaymentGateway
{
    /** Xendit refuses a session that expires sooner than this after creation. */
    public const MIN_SESSION_SECONDS = 630;

    public const CHANNELS = ['QRPH', 'GCASH'];

    private const PAYMENTS_API_VERSION = '2024-11-11';

    public function __construct(
        private string $secretKey,
        private string $endpoint,
        private string $returnUrl,
        private int $timeout = 15,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.xendit.secret_key'),
            rtrim((string) config('services.xendit.endpoint'), '/'),
            (string) config('services.xendit.return_url'),
            (int) config('services.xendit.timeout', 15),
        );
    }

    public function createSession(
        string $reference,
        float $amount,
        string $description,
        CarbonImmutable $expiresAt,
        array $metadata = [],
        bool $toApp = false,
    ): GatewaySession {
        $earliest = CarbonImmutable::now()->addSeconds(self::MIN_SESSION_SECONDS);

        $body = $this->send(fn () => $this->client()->post('/sessions', [
            'reference_id' => $reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => round($amount, 2),
            'currency' => 'PHP',
            'country' => 'PH',
            'allowed_payment_channels' => self::CHANNELS,
            'expires_at' => ($expiresAt->greaterThan($earliest) ? $expiresAt : $earliest)
                ->utc()->format('Y-m-d\TH:i:s\Z'),
            'description' => $description,
            'metadata' => (object) $metadata,
            'success_return_url' => $this->returnUrl($toApp ? ['source' => 'app', 'result' => 'success'] : []),
            'cancel_return_url' => $this->returnUrl($toApp ? ['source' => 'app', 'result' => 'cancelled'] : []),
        ]));

        return GatewaySession::fromXendit($body);
    }

    public function getSession(string $sessionId): GatewaySession
    {
        return GatewaySession::fromXendit(
            $this->send(fn () => $this->client()->get('/sessions/'.rawurlencode($sessionId)))
        );
    }

    public function cancelSession(string $sessionId): GatewaySession
    {
        try {
            return GatewaySession::fromXendit(
                $this->send(fn () => $this->client()->post('/sessions/'.rawurlencode($sessionId).'/cancel'))
            );
        } catch (PaymentGatewayException $e) {
            // Already finished one way or another: report how it finished.
            if ($e->status !== null && $e->status >= 400 && $e->status < 500) {
                return $this->getSession($sessionId);
            }

            throw $e;
        }
    }

    public function paymentChannel(string $paymentRequestId): ?string
    {
        $body = $this->send(fn () => $this->client()
            ->withHeaders(['api-version' => self::PAYMENTS_API_VERSION])
            ->get('/v3/payment_requests/'.rawurlencode($paymentRequestId)));

        $channel = $body['channel_code'] ?? null;

        return is_string($channel) && $channel !== '' ? strtoupper($channel) : null;
    }

    /**
     * Xendit takes HTTPS only. A local web app runs on plain http, so locally
     * the scheme is raised: the session is still created, and the customer
     * comes back to the order page by hand. Deployed, FRONTEND_URL is already
     * HTTPS and nothing changes.
     *
     * The native app's checkouts add `source=app` and the outcome (decided
     * 2026-11-19): the page then sends the customer back to the app instead of
     * showing the website inside the app's browser tab. Still no order or
     * token in the address.
     *
     * @param  array<string, string>  $query
     */
    private function returnUrl(array $query = []): string
    {
        $url = preg_replace('#^http://#i', 'https://', $this->returnUrl) ?? $this->returnUrl;

        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    private function client(): PendingRequest
    {
        if ($this->secretKey === '') {
            throw new PaymentGatewayException('Payments are not configured: XENDIT_SECRET_KEY is empty.');
        }

        return Http::baseUrl($this->endpoint)
            ->withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);
    }

    /** @return array<string, mixed> */
    private function send(callable $call): array
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Xendit could not be reached: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new PaymentGatewayException(
                'Xendit refused the request: '.($response->json('message') ?? 'HTTP '.$response->status()),
                $response->status(),
                $response->json('error_code'),
            );
        }

        return (array) $response->json();
    }
}
