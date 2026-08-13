<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PhilSMS transport.
 *
 * Response envelope, confirmed against live calls on 2026-08-13 as well as the
 * vendor doc in docs/PhilSms.docx:
 *
 *   success -> {"status":"success","data": ...}
 *   error   -> {"status":"error","message":"A human-readable description"}
 *
 * Two things that matter and are easy to get wrong:
 *
 *  1. The HTTP status is NOT a reliable signal. Live GET calls answered 200 with
 *     a status=error body, and /sms/send answered 404 on a rejected sender ID.
 *     Success is determined solely by the `status` field.
 *  2. `data` is a placeholder in the vendor doc ("sms reports with all details")
 *     and is deliberately not parsed. Only `status` and `message` are read, both
 *     observed live. The whole body is logged so the real success shape is on
 *     record from the first send onward.
 *
 * sender_id comes from config (PHILSMS_SENDER_ID) and is never hardcoded. This
 * account cannot hold an approved alphanumeric brand ID, so it runs on a plain
 * phone number, which the vendor doc explicitly permits in the same field
 * ("a telephone number (including country code) or an alphanumeric string").
 * Swapping to a brand ID later is an env change, not a code change.
 */
class PhilSmsClient implements SmsSender
{
    public function __construct(
        private string $endpoint,
        private string $token,
        private string $senderId,
        private int $timeoutSeconds = 15,
    ) {
    }

    public function send(string $to, string $message): void
    {
        $payload = [
            'recipient' => $this->formatNumber($to),
            'sender_id' => $this->formatNumber($this->senderId),
            'type' => 'plain',
            'message' => $message,
        ];

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                // Every outbound call in this app was flagged in the audit for
                // having no timeout; Guzzle's default is wait-forever.
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(5)
                ->post($this->endpoint, $payload);
        } catch (Throwable $e) {
            Log::error('PhilSMS transport failure', ['error' => $e->getMessage()]);

            throw new SmsDeliveryException('Could not reach the SMS provider.', 0, $e);
        }

        $body = $response->json();

        // Logged in full so the success-path `data` shape is captured. The message
        // body is deliberately excluded - it holds the OTP.
        Log::info('PhilSMS response', [
            'http_status' => $response->status(),
            'body' => $body,
        ]);

        if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
            $reason = is_array($body)
                ? ($body['message'] ?? 'Unknown provider error.')
                : 'Malformed provider response.';

            throw new SmsDeliveryException("PhilSMS rejected the message: {$reason}");
        }
    }

    /**
     * PhilSMS wants bare MSISDN with the country code and no '+' (639171234567),
     * for both the recipient and a numeric sender_id.
     *
     * Alphanumeric values pass through untouched so a future approved brand
     * sender ID keeps working without a code change.
     */
    private function formatNumber(string $value): string
    {
        $value = trim($value);
        $digits = preg_replace('/\D/', '', $value);

        // Not a phone number - an alphanumeric sender ID. Leave it alone.
        if ($digits === '' || ! ctype_digit(ltrim($value, '+'))) {
            return $value;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            return $digits;
        }

        // 09XXXXXXXXX -> 639XXXXXXXXX
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '63'.substr($digits, 1);
        }

        // 9XXXXXXXXX -> 639XXXXXXXXX
        if (strlen($digits) === 10) {
            return '63'.$digits;
        }

        return $digits;
    }
}
