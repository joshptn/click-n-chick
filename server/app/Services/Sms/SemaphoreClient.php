<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Semaphore OTP transport - https://api.semaphore.co/api/v4/otp
 *
 * Per the vendor doc (docs/Semaphore Documentation about their OTP Messages.pdf):
 *
 *  - Traffic is routed over a dedicated OTP route, so it still lands when telcos
 *    are congested. It costs 2 credits per 160-character segment, hence the hard
 *    length ceiling enforced upstream in OtpService.
 *  - The message carries a '{otp}' placeholder and the code rides in its own
 *    `code` parameter. Without the placeholder Semaphore APPENDS a code of its
 *    own - which, since this app generates and stores its own, would text the
 *    user a second code that verifies nothing.
 *  - `code` in the response is an echo of what was sent. It is deliberately not
 *    read, not logged and not trusted.
 *  - No sender name is set, so messages come from the default "SEMAPHORE".
 *  - THIS ENDPOINT IS NOT RATE LIMITED by Semaphore. Every limit protecting it
 *    lives in this app (AppServiceProvider's otp-send/otp-verify limiters and
 *    OtpService's resend cooldown). Nothing upstream catches a mistake here.
 *
 * A successful response is a JSON array of message objects.
 */
class SemaphoreClient implements SmsSender
{
    /**
     * Response fields safe to log. Explicit allowlist, not a blocklist: `message`
     * and `code` both carry the OTP and must never reach the log.
     */
    private const LOGGABLE_FIELDS = [
        'message_id',
        'recipient',
        'status',
        'network',
        'type',
        'sender_name',
        'created_at',
    ];

    /** Semaphore statuses that mean the message was not accepted. */
    private const FAILURE_STATUSES = ['failed', 'refunded'];

    /**
     * Raw decoded body of the most recent call, held in memory only.
     *
     * Exists solely for the deliberate `otp:test-send` verification command,
     * which needs to show the operator the real response shape once. It is never
     * logged and never persisted - the logger keeps its own allowlist.
     */
    private mixed $lastResponse = null;

    public function __construct(
        private string $endpoint,
        private string $apiKey,
        /**
         * Optional. Left empty the request omits `sendername` entirely, which the
         * doc says falls back to the account default. On an account with no
         * registered sender name that fallback does not exist, so this can be set
         * explicitly (SEMAPHORE_SENDER_NAME) without a code change.
         */
        private ?string $senderName = null,
        private int $timeoutSeconds = 15,
    ) {
    }

    public function lastResponse(): mixed
    {
        return $this->lastResponse;
    }

    public function send(string $to, string $message, ?string $otpCode = null): void
    {
        try {
            // Form-encoded, matching the documented curl example.
            $response = Http::asForm()
                ->acceptJson()
                // Guzzle defaults to waiting forever on both; an OTP send sits in
                // the registration request path and must not hang it.
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(5)
                ->post($this->endpoint, array_filter([
                    'apikey' => $this->apiKey,
                    'number' => $this->formatNumber($to),
                    'message' => $message,
                    // Our code, not theirs. Skips the auto-generated one.
                    'code' => $otpCode,
                    // Omitted when unset, so the account default applies.
                    'sendername' => $this->senderName,
                ], fn ($value) => $value !== null && $value !== ''));
        } catch (Throwable $e) {
            Log::error('Semaphore transport failure', ['error' => $e->getMessage()]);

            throw new SmsDeliveryException('Could not reach the SMS provider.', 0, $e);
        }

        $body = $response->json();
        $this->lastResponse = $body;

        Log::info('Semaphore response', [
            'http_status' => $response->status(),
            'messages' => $this->loggableSummary($body),
        ]);

        if (! $response->successful()) {
            throw new SmsDeliveryException(
                'Semaphore rejected the message (HTTP '.$response->status().'): '
                    .$this->errorText($body)
            );
        }

        // Documented success shape is a non-empty array of message objects.
        if (! is_array($body) || $body === [] || ! is_array($body[0] ?? null)) {
            throw new SmsDeliveryException(
                'Semaphore returned an unexpected response: '.$this->errorText($body)
            );
        }

        $status = strtolower((string) ($body[0]['status'] ?? ''));

        if (in_array($status, self::FAILURE_STATUSES, true)) {
            throw new SmsDeliveryException("Semaphore reported the message as {$status}.");
        }
    }

    /**
     * Reduce the response to the allowlisted fields only, so neither the message
     * text nor the echoed code can leak into the log.
     */
    private function loggableSummary(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $rows = is_array($body[0] ?? null) ? $body : [$body];

        return array_map(
            fn ($row) => is_array($row)
                ? array_intersect_key($row, array_flip(self::LOGGABLE_FIELDS))
                : [],
            array_slice($rows, 0, 5)
        );
    }

    /**
     * A short, log-safe description of a failure. Semaphore returns validation
     * errors keyed by field; the values are field messages, never the OTP.
     */
    private function errorText(mixed $body): string
    {
        if (is_array($body)) {
            $flat = [];
            array_walk_recursive($body, function ($value, $key) use (&$flat) {
                if (! in_array($key, ['message', 'code'], true) && is_scalar($value)) {
                    $flat[] = (string) $value;
                }
            });

            if ($flat !== []) {
                return implode('; ', array_slice($flat, 0, 5));
            }
        }

        return 'no parsable error detail';
    }

    /**
     * Semaphore's own response example shows '639998887777' - country code, no
     * '+', no leading zero. Normalised here from whatever PH shape we hold.
     */
    private function formatNumber(string $value): string
    {
        $digits = preg_replace('/\D/', '', trim($value));

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            return $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '63'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '63'.$digits;
        }

        return $digits;
    }
}
