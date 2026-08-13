<?php

namespace Tests\Feature;

use App\Services\Otp\OtpService;
use App\Services\Sms\SemaphoreClient;
use App\Services\Sms\SmsDeliveryException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the wire format against the vendor doc's PHP cURL example
 * (http_build_query + CURLOPT_POSTFIELDS), which is form-urlencoded. Laravel's
 * Http::asForm() produces a byte-identical body, so no hand-rolled curl_* code
 * is needed - but the shape must not drift.
 */
class SemaphoreClientTest extends TestCase
{
    private function client(?string $senderName = 'SEMAPHORE'): SemaphoreClient
    {
        return new SemaphoreClient('https://api.semaphore.co/api/v4/otp', 'TESTKEY', $senderName);
    }

    private function fakeAccepted(): void
    {
        Http::fake(['*' => Http::response([[
            'message_id' => 1,
            'recipient' => '639356363607',
            'message' => 'redacted',
            'code' => 482913,
            'status' => 'Pending',
            'network' => 'Globe',
            'type' => 'Single',
        ]], 200)]);
    }

    public function test_the_body_matches_the_documented_form_encoded_payload(): void
    {
        $this->fakeAccepted();

        $this->client()->send('09356363607', OtpService::messageTemplate(), '482913');

        Http::assertSent(function ($request) {
            parse_str($request->body(), $fields);

            $expected = http_build_query([
                'apikey' => 'TESTKEY',
                'number' => '639356363607',
                'message' => OtpService::messageTemplate(),
                'code' => '482913',
                'sendername' => 'SEMAPHORE',
            ]);
            parse_str($expected, $expectedFields);

            ksort($fields);
            ksort($expectedFields);

            return $request->method() === 'POST'
                && str_contains(implode(',', $request->header('Content-Type')), 'application/x-www-form-urlencoded')
                && $fields === $expectedFields;
        });
    }

    public function test_the_otp_placeholder_survives_url_encoding(): void
    {
        $this->fakeAccepted();

        $this->client()->send('09356363607', OtpService::messageTemplate(), '482913');

        Http::assertSent(function ($request) {
            parse_str($request->body(), $fields);

            // '{' and '}' encode to %7B/%7D on the wire; Semaphore decodes them
            // back and does the substitution from the `code` field.
            return str_contains($request->body(), '%7Botp%7D')
                && str_contains($fields['message'], '{otp}')
                && ! str_contains($fields['message'], '482913');
        });
    }

    public function test_the_message_never_starts_with_the_word_test(): void
    {
        // The doc: messages beginning with "TEST" are silently ignored and never
        // sent - a failure mode with no error to catch.
        $rendered = str_replace('{otp}', '482913', OtpService::messageTemplate());

        $this->assertFalse(str_starts_with(strtoupper(ltrim($rendered)), 'TEST'));
    }

    public function test_sendername_is_omitted_entirely_when_unset(): void
    {
        $this->fakeAccepted();

        $this->client(null)->send('09356363607', OtpService::messageTemplate(), '482913');

        Http::assertSent(function ($request) {
            parse_str($request->body(), $fields);

            // Absent, not empty - an empty sendername is rejected as invalid.
            return ! array_key_exists('sendername', $fields);
        });
    }

    public function test_a_failed_status_is_treated_as_a_rejection(): void
    {
        Http::fake(['*' => Http::response([['message_id' => 1, 'status' => 'Failed']], 200)]);

        $this->expectException(SmsDeliveryException::class);

        $this->client()->send('09356363607', OtpService::messageTemplate(), '482913');
    }

    public function test_the_sender_name_error_shape_is_surfaced(): void
    {
        // The exact body the live API returned on an account with no sender name.
        Http::fake(['*' => Http::response([[
            'senderName' => 'No active sender name found. Please apply for a sender name before sending messages.',
        ]], 500)]);

        $this->expectException(SmsDeliveryException::class);
        $this->expectExceptionMessageMatches('/sender name/i');

        $this->client(null)->send('09356363607', OtpService::messageTemplate(), '482913');
    }
}
