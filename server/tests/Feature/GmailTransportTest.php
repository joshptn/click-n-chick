<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Services\Mail\GmailOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Tests\TestCase;

class GmailTransportTest extends TestCase
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'mail.from.address' => 'store@example.test',
            'services.gmail.client_id' => 'client-id',
            'services.gmail.client_secret' => 'client-secret',
            'services.gmail.refresh_token' => 'refresh-token',
        ]);
    }

    private function sendCode(string $code = '482913', array $bcc = []): void
    {
        $pending = Mail::mailer('gmail')->to('customer@example.test');

        if ($bcc !== []) {
            $pending->bcc($bcc);
        }

        $pending->send(new VerificationCodeMail($code));
    }

    private function rawSent(): string
    {
        $raw = Http::recorded(fn (Request $request) => $request->url() === self::SEND_URL)->first()[0]['raw'];

        return base64_decode(strtr($raw, '-_', '+/'));
    }

    private function fakeGoogle(array $send = []): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'access-1', 'expires_in' => 3599]),
            self::SEND_URL => $send === [] ? Http::response(['id' => 'gmail-id-1']) : Http::sequence($send),
        ]);
    }

    public function test_a_mailable_is_delivered_through_the_gmail_api(): void
    {
        $this->fakeGoogle();

        $this->sendCode('482913');

        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-token'
            && $request['client_id'] === 'client-id');

        Http::assertSent(fn (Request $request) => $request->url() === self::SEND_URL
            && $request->hasHeader('Authorization', 'Bearer access-1'));

        $raw = $this->rawSent();
        $this->assertStringContainsString('To: customer@example.test', $raw);
        $this->assertStringContainsString('From: ', $raw);
        $this->assertStringContainsString('482913 is your Click n Chick verification code', $raw);
        $this->assertStringNotContainsString('Bcc:', $raw);
    }

    public function test_the_access_token_is_reused_across_sends(): void
    {
        $this->fakeGoogle();

        $this->sendCode();
        $this->sendCode();

        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->url() === self::TOKEN_URL));
        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->url() === self::SEND_URL));
    }

    public function test_a_revoked_access_token_is_replaced_once_and_the_send_retried(): void
    {
        $this->fakeGoogle([
            Http::response(['error' => ['message' => 'Invalid Credentials']], 401),
            Http::response(['id' => 'gmail-id-2']),
        ]);

        $this->sendCode();

        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->url() === self::TOKEN_URL));
        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->url() === self::SEND_URL));
    }

    public function test_a_rejected_message_fails_the_send_without_retrying(): void
    {
        $this->fakeGoogle([
            Http::response(['error' => ['message' => 'Invalid To header']], 400),
        ]);

        try {
            $this->sendCode();
            $this->fail('A rejected message must not pass for a sent one.');
        } catch (TransportExceptionInterface $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('Invalid To header', $e->getMessage());
        }

        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->url() === self::SEND_URL));
    }

    public function test_a_refresh_token_google_no_longer_honours_fails_the_send(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
        ]);

        try {
            $this->sendCode();
            $this->fail('A send without an access token must fail.');
        } catch (TransportExceptionInterface $e) {
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
            $this->assertStringNotContainsString('refresh-token', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => $request->url() === self::SEND_URL);
    }

    public function test_missing_credentials_fail_the_send_and_call_nothing(): void
    {
        config(['services.gmail.refresh_token' => null]);
        Http::fake();

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessage('GMAIL_REFRESH_TOKEN');

        try {
            $this->sendCode();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_blind_copies_reach_gmail_as_a_bcc_header(): void
    {
        $this->fakeGoogle();

        $this->sendCode('482913', ['audit@example.test']);

        $raw = $this->rawSent();
        $this->assertStringStartsWith('Bcc: audit@example.test', $raw);
        $this->assertStringContainsString('To: customer@example.test', $raw);
    }

    public function test_rotating_the_refresh_token_does_not_reuse_the_old_access_token(): void
    {
        $this->fakeGoogle();

        $this->sendCode();

        config(['services.gmail.refresh_token' => 'rotated-token']);
        app('mail.manager')->purge('gmail');

        $this->sendCode();

        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->url() === self::TOKEN_URL));
    }

    public function test_the_authorize_command_exchanges_the_pasted_code_for_a_refresh_token(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'new-refresh-token',
                'scope' => GmailOAuth::SCOPE,
            ]),
        ]);

        $this->artisan('mail:gmail-authorize')
            ->expectsQuestion('Address (or just the code)', 'the-code')
            ->expectsOutputToContain('new-refresh-token')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'the-code'
            && $request['redirect_uri'] === 'http://127.0.0.1');
    }

    public function test_the_authorize_command_refuses_an_address_from_another_attempt(): void
    {
        Http::fake();

        $this->artisan('mail:gmail-authorize')
            ->expectsQuestion('Address (or just the code)', 'http://127.0.0.1/?state=someone-elses&code=the-code&scope='.urlencode(GmailOAuth::SCOPE))
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_authorize_command_refuses_a_grant_without_the_send_scope(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['refresh_token' => 'new-refresh-token', 'scope' => 'openid']),
        ]);

        $this->artisan('mail:gmail-authorize')
            ->expectsQuestion('Address (or just the code)', 'the-code')
            ->doesntExpectOutputToContain('new-refresh-token')
            ->assertFailed();
    }
}
