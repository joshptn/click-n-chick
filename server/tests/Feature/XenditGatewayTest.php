<?php

namespace Tests\Feature;

use App\Services\Payments\GatewaySession;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\XenditGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real Xendit client against faked HTTP: the requests it sends match what
 * Xendit's test mode accepted on 2026-11-17, and its answers are read right.
 */
class XenditGatewayTest extends TestCase
{
    private function gateway(string $returnUrl = 'http://localhost:5173/payment/return'): XenditGateway
    {
        return new XenditGateway('xnd_development_fake', 'https://api.xendit.co', $returnUrl);
    }

    private function sessionBody(array $overrides = []): array
    {
        return array_merge([
            'payment_session_id' => 'ps-123',
            'status' => 'ACTIVE',
            'reference_id' => 'cnc-order-1-payment-1',
            'amount' => 130,
            'expires_at' => '2026-11-17T10:59:06.000Z',
            'payment_link_url' => 'https://xen.to/abc',
        ], $overrides);
    }

    public function test_a_session_offers_qr_ph_and_gcash_and_returns_over_https(): void
    {
        CarbonImmutable::setTestNow('2026-11-17 10:00:00');
        Http::fake(['api.xendit.co/sessions' => Http::response($this->sessionBody(), 201)]);

        $session = $this->gateway()->createSession(
            'cnc-order-1-payment-1',
            130,
            'Click n Chick order #1',
            CarbonImmutable::now()->addMinutes(10),
            ['order_id' => '1'],
        );

        $this->assertSame('ps-123', $session->id);
        $this->assertSame('https://xen.to/abc', $session->url);
        $this->assertTrue($session->isUsable(CarbonImmutable::parse('2026-11-17 10:50:00')));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.xendit.co/sessions'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_development_fake:'))
                && $body['session_type'] === 'PAY'
                && $body['mode'] === 'PAYMENT_LINK'
                && $body['currency'] === 'PHP'
                && $body['country'] === 'PH'
                && $body['allowed_payment_channels'] === ['QRPH', 'GCASH']
                && $body['success_return_url'] === 'https://localhost:5173/payment/return'
                && $body['cancel_return_url'] === 'https://localhost:5173/payment/return'
                // Xendit refuses less than ten minutes; we ask ten and a half.
                && $body['expires_at'] === '2026-11-17T10:10:30Z';
        });

        CarbonImmutable::setTestNow();
    }

    public function test_a_session_opened_from_the_app_returns_to_the_app_page(): void
    {
        Http::fake(['api.xendit.co/sessions' => Http::response($this->sessionBody(), 201)]);

        $this->gateway()->createSession('cnc-order-1-payment-1', 130, 'd', CarbonImmutable::now()->addHour(), [], true);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['success_return_url'] === 'https://localhost:5173/payment/return?source=app&result=success'
                && $body['cancel_return_url'] === 'https://localhost:5173/payment/return?source=app&result=cancelled';
        });
    }

    public function test_a_completed_session_carries_its_payment(): void
    {
        Http::fake(['api.xendit.co/sessions/ps-123' => Http::response($this->sessionBody([
            'status' => 'COMPLETED',
            'payment_id' => 'py-9',
            'payment_request_id' => 'pr-9',
        ]))]);

        $session = $this->gateway()->getSession('ps-123');

        $this->assertTrue($session->isCompleted());
        $this->assertSame('py-9', $session->paymentId);
        $this->assertSame('pr-9', $session->paymentRequestId);
    }

    public function test_cancelling_a_finished_session_reports_how_it_finished(): void
    {
        Http::fake([
            'api.xendit.co/sessions/ps-123/cancel' => Http::response(['error_code' => 'INVALID_STATUS', 'message' => 'Session is not active'], 400),
            'api.xendit.co/sessions/ps-123' => Http::response($this->sessionBody(['status' => 'COMPLETED', 'payment_id' => 'py-9'])),
        ]);

        $session = $this->gateway()->cancelSession('ps-123');

        $this->assertSame(GatewaySession::COMPLETED, $session->status);
    }

    public function test_the_channel_is_read_from_the_payment_request(): void
    {
        Http::fake(['api.xendit.co/v3/payment_requests/pr-9' => Http::response(['channel_code' => 'QRPH'])]);

        $this->assertSame('QRPH', $this->gateway()->paymentChannel('pr-9'));

        Http::assertSent(fn (Request $request) => $request->hasHeader('api-version', '2024-11-11'));
    }

    public function test_a_refusal_carries_the_error_code_xendit_gave(): void
    {
        Http::fake(['api.xendit.co/sessions' => Http::response(['error_code' => 'INVALID_URL', 'message' => 'Please provide a valid HTTPS URL'], 400)]);

        try {
            $this->gateway()->createSession('r', 1, 'd', CarbonImmutable::now()->addHour());
            $this->fail('Expected a refusal.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('INVALID_URL', $e->errorCode);
        }
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        Http::fake();

        $this->expectException(PaymentGatewayException::class);

        try {
            (new XenditGateway('', 'https://api.xendit.co', 'https://example.test'))->getSession('ps-1');
        } finally {
            Http::assertNothingSent();
        }
    }
}
