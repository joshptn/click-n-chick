<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\NativeApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * reCAPTCHA v2 Invisible gating (decided 2026-11-22).
 *
 * Every exchange with Google is faked. These assert how the application treats
 * a verdict, never that Google produced one; `php artisan recaptcha:test-verify`
 * is the live check.
 */
class RecaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * The routes that need no account and send a code or create something.
     * Everything else was dropped on 2026-11-22.
     */
    private const GUARDED = [
        'POST api/register',
        'POST api/otp/resend',
        'POST api/login',
        'POST api/password/forgot',
        'POST api/guest/orders',
    ];

    /** Credentials the way a provisioned account would supply them. */
    private function enableRecaptcha(): void
    {
        config()->set('services.recaptcha.enabled', true);
        config()->set('services.recaptcha.site_key', 'test-site-key');
        config()->set('services.recaptcha.secret_key', 'test-secret-key');
    }

    private function fakeVerdict(array $body): void
    {
        Http::fake([self::VERIFY_URL => Http::response($body, 200)]);
    }

    private function activeUser(): User
    {
        $phone = '+639171234567';

        return User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => 'email',
            'account_status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ])->fresh();
    }

    private function login(array $extra = [])
    {
        return $this->postJson('/api/login', array_merge([
            'login' => $this->user->email,
            'password' => self::PASSWORD,
        ], $extra));
    }

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->activeUser();
    }

    // -----------------------------------------------------------------
    // Unconfigured: every guarded flow still works
    // -----------------------------------------------------------------

    public function test_guarded_routes_pass_through_while_recaptcha_is_unconfigured(): void
    {
        Http::fake();

        $this->login()->assertOk()->assertJsonStructure(['user', 'token']);

        Http::assertNothingSent();
    }

    public function test_a_partially_configured_recaptcha_stays_inert(): void
    {
        // A site key with no secret cannot verify anything; treating that as
        // "on" would lock every guarded endpoint.
        config()->set('services.recaptcha.enabled', true);
        config()->set('services.recaptcha.site_key', 'test-site-key');
        config()->set('services.recaptcha.secret_key', null);

        Http::fake();

        $this->login()->assertOk();

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // The config endpoint
    // -----------------------------------------------------------------

    public function test_the_config_endpoint_withholds_the_site_key_while_disabled(): void
    {
        $this->getJson('/api/config/recaptcha')
            ->assertOk()
            ->assertExactJson(['enabled' => false, 'site_key' => null]);
    }

    public function test_the_config_endpoint_publishes_only_the_site_key_once_configured(): void
    {
        $this->enableRecaptcha();

        $response = $this->getJson('/api/config/recaptcha')
            ->assertOk()
            ->assertExactJson(['enabled' => true, 'site_key' => 'test-site-key']);

        // The secret must never leave the server.
        $this->assertStringNotContainsString('test-secret-key', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Verdicts
    // -----------------------------------------------------------------

    public function test_a_request_with_no_token_is_refused_once_configured(): void
    {
        $this->enableRecaptcha();
        Http::fake();

        $this->login()
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECAPTCHA_FAILED')
            ->assertJsonPath('reason', 'missing')
            ->assertJsonPath('message', 'Please complete the security check and try again.');

        // Rejected before Google is consulted - a missing token needs no call.
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // The native app (FR-01.8, O-06)
    // -----------------------------------------------------------------

    public function test_the_native_app_signs_in_without_a_token_and_google_is_not_asked(): void
    {
        $this->enableRecaptcha();
        Http::fake();

        $this->withHeader(NativeApp::HEADER, NativeApp::ANDROID)
            ->login()
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);

        Http::assertNothingSent();
    }

    public function test_the_native_marker_covers_every_guarded_flow(): void
    {
        $this->enableRecaptcha();
        Http::fake();

        // Each reaches its controller (any answer but the reCAPTCHA refusal).
        foreach ([
            ['/api/register', ['identifier' => 'native@example.test', 'first_name' => 'A', 'last_name' => 'B', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]],
            ['/api/password/forgot', ['identifier' => $this->user->email]],
            ['/api/otp/resend', ['identifier' => 'native@example.test']],
        ] as [$uri, $body]) {
            $response = $this->withHeader(NativeApp::HEADER, NativeApp::ANDROID)->postJson($uri, $body);

            $this->assertNotSame('RECAPTCHA_FAILED', $response->json('error_code'), $uri);
        }

        Http::assertNothingSent();
    }

    public function test_a_wrong_marker_value_is_still_refused(): void
    {
        $this->enableRecaptcha();
        Http::fake();

        $this->withHeader(NativeApp::HEADER, 'something-else')
            ->login()
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECAPTCHA_FAILED');
    }

    public function test_the_native_app_shows_by_name_in_the_device_list(): void
    {
        $this->withHeaders([
            NativeApp::HEADER => NativeApp::ANDROID,
            'User-Agent' => 'ClickNChickApp/1.0 (Android 15; Pixel 8)',
        ])->login()->assertOk();

        $device = $this->user->knownDevices()->sole();

        $this->assertSame('Click n Chick app on Android', $device->device_name);
        $this->assertSame('Android', $device->platform);
    }

    public function test_a_passing_token_lets_the_request_through(): void
    {
        $this->enableRecaptcha();
        // What v2 answers: no score and no action, only whether it passed.
        $this->fakeVerdict(['success' => true, 'challenge_ts' => now()->toIso8601String(), 'hostname' => 'localhost']);

        $this->login(['recaptcha_token' => 'good-token'])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_the_token_may_also_arrive_as_a_header(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true]);

        $this->withHeader('X-Recaptcha-Token', 'good-token')->login()->assertOk();
    }

    public function test_a_token_google_rejects_is_refused(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->login(['recaptcha_token' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECAPTCHA_FAILED')
            ->assertJsonPath('reason', 'invalid')
            ->assertJsonPath('message', 'Please complete the security check and try again.');
    }

    /** Tokens are single-use and last two minutes; the browser gets a new one on the next tap. */
    public function test_an_expired_or_reused_token_says_so(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        $this->login(['recaptcha_token' => 'used-token'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'expired')
            ->assertJsonPath('message', 'The security check expired. Please try again.');
    }

    public function test_an_unreadable_answer_is_refused(): void
    {
        $this->enableRecaptcha();
        Http::fake([self::VERIFY_URL => Http::response('not json', 200)]);

        $this->login(['recaptcha_token' => 'good-token'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid');
    }

    /** Low-score handling went with v3: sign-in never answers with a code challenge of its own. */
    public function test_sign_in_never_steps_up(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'score' => 0.1, 'action' => 'login']);

        $this->login(['recaptcha_token' => 'good-token'])
            ->assertOk()
            ->assertJsonMissingPath('two_factor_required')
            ->assertJsonStructure(['token']);
    }

    public function test_the_secret_and_caller_ip_are_sent_to_google_and_the_secret_never_returns(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true]);

        $response = $this->login(['recaptcha_token' => 'good-token'])->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === self::VERIFY_URL
                && $request['secret'] === 'test-secret-key'
                && $request['response'] === 'good-token'
                && ! empty($request['remoteip']);
        });

        $this->assertStringNotContainsString('test-secret-key', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Availability
    // -----------------------------------------------------------------

    public function test_verification_fails_open_when_google_cannot_be_reached(): void
    {
        $this->enableRecaptcha();

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        // Kept 2026-11-22: an outage at Google must not take sign-in down with
        // it. The rate limiters stay in force regardless.
        $this->login(['recaptcha_token' => 'good-token'])->assertOk();
    }

    /** A dropped connection is tried once more, so a flaky network does not switch the check off. */
    public function test_a_dropped_connection_is_tried_again_before_failing_open(): void
    {
        $this->enableRecaptcha();

        Http::fakeSequence(self::VERIFY_URL)
            ->pushFailedConnection()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->login(['recaptcha_token' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid');

        Http::assertSentCount(2);
    }

    public function test_verification_fails_open_on_a_server_error_from_google(): void
    {
        $this->enableRecaptcha();
        Http::fake([self::VERIFY_URL => Http::response('', 503)]);

        $this->login(['recaptcha_token' => 'good-token'])->assertOk();
    }

    public function test_a_missing_verify_url_does_not_silently_disable_the_protection(): void
    {
        // Guards a real regression: the config key was dropped once, the post
        // went to an empty URL, that threw, the outage path failed open, and
        // every guarded route quietly stopped checking anything.
        $this->enableRecaptcha();
        config()->set('services.recaptcha.verify_url', null);

        $this->fakeVerdict(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->login(['recaptcha_token' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid');
    }

    // -----------------------------------------------------------------
    // Which routes are guarded
    // -----------------------------------------------------------------

    public function test_exactly_the_five_routes_carry_the_check(): void
    {
        $actual = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && ($middleware === 'recaptcha' || str_starts_with($middleware, 'recaptcha:'))) {
                    $actual[] = $route->methods()[0].' '.$route->uri();
                }
            }
        }

        $expected = self::GUARDED;
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_every_guarded_route_refuses_a_request_with_no_token(): void
    {
        $this->enableRecaptcha();
        Http::fake();

        foreach ([
            '/api/register' => ['identifier' => 'new@example.test', 'first_name' => 'A', 'last_name' => 'B', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD],
            '/api/otp/resend' => ['identifier' => 'new@example.test'],
            '/api/login' => ['login' => $this->user->email, 'password' => self::PASSWORD],
            '/api/password/forgot' => ['identifier' => $this->user->email],
            '/api/guest/orders' => [],
        ] as $uri => $body) {
            $this->postJson($uri, $body)
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'RECAPTCHA_FAILED');
        }

        Http::assertNothingSent();
    }

    /** The routes where a code is typed: the code is the proof, so no token is asked for. */
    public function test_entering_a_code_needs_no_token(): void
    {
        $this->enableRecaptcha();
        Http::fake();

        foreach ([
            '/api/otp/verify' => ['identifier' => 'new@example.test', 'code' => '123456'],
            '/api/password/reset' => ['identifier' => $this->user->email, 'code' => '123456', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD],
            '/api/2fa/challenge' => ['challenge_token' => 'unknown', 'code' => '123456'],
            '/api/2fa/resend' => ['challenge_token' => 'unknown'],
        ] as $uri => $body) {
            $this->assertNotSame('RECAPTCHA_FAILED', $this->postJson($uri, $body)->json('error_code'), $uri);
        }

        Http::assertNothingSent();
    }
}
