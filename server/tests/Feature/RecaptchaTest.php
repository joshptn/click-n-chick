<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Recaptcha\RecaptchaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * reCAPTCHA v3 gating.
 *
 * Structural only. Every exchange with Google is faked - no live siteverify
 * call is made and no real key is used, because none exists yet. These assert
 * how the application treats a verdict, never that Google produced one.
 */
class RecaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /** Credentials the way a provisioned account would supply them. */
    private function enableRecaptcha(float $minScore = 0.5): void
    {
        config()->set('services.recaptcha.enabled', true);
        config()->set('services.recaptcha.site_key', 'test-site-key');
        config()->set('services.recaptcha.secret_key', 'test-secret-key');
        config()->set('services.recaptcha.min_score', $minScore);
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
        // No RECAPTCHA_* keys in the test environment, which is the state the
        // application is in today.
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
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('site_key', null);
    }

    public function test_the_config_endpoint_publishes_the_site_key_and_actions_once_configured(): void
    {
        $this->enableRecaptcha();

        $response = $this->getJson('/api/config/recaptcha')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('site_key', 'test-site-key');

        // The browser reads its action names from here rather than hardcoding
        // them, so a route and its form cannot drift apart.
        $this->assertSame(RecaptchaAction::all(), $response->json('actions'));

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
            ->assertJsonPath('reason', 'missing');

        // Rejected before Google is consulted - a missing token needs no call.
        Http::assertNothingSent();
    }

    public function test_a_valid_token_lets_the_request_through(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::LOGIN, 'score' => 0.9]);

        $this->login(['recaptcha_token' => 'good-token'])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_the_token_may_also_arrive_as_a_header(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::LOGIN, 'score' => 0.9]);

        $this->withHeader('X-Recaptcha-Token', 'good-token')->login()->assertOk();
    }

    public function test_a_token_google_rejects_is_refused(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->login(['recaptcha_token' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid');
    }

    public function test_a_token_scoring_below_the_threshold_is_refused(): void
    {
        $this->enableRecaptcha(0.5);
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::LOGIN, 'score' => 0.1]);

        $this->login(['recaptcha_token' => 'bot-token'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'low_score');
    }

    public function test_the_score_threshold_is_configurable(): void
    {
        $this->enableRecaptcha(0.05);
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::LOGIN, 'score' => 0.1]);

        $this->login(['recaptcha_token' => 'borderline'])->assertOk();
    }

    public function test_a_token_minted_for_another_action_cannot_be_replayed(): void
    {
        $this->enableRecaptcha();

        // A perfectly valid, high-scoring token - but issued on the register
        // form. Without the action check it would open the login endpoint too.
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::REGISTER, 'score' => 0.9]);

        $this->login(['recaptcha_token' => 'wrong-form-token'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'action_mismatch');
    }

    public function test_a_v2_style_token_carrying_no_action_is_refused(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'score' => 0.9]);

        $this->login(['recaptcha_token' => 'v2-token'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'action_mismatch');
    }

    public function test_a_verdict_with_no_score_is_refused(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::LOGIN]);

        $this->login(['recaptcha_token' => 'scoreless'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'low_score');
    }

    public function test_the_secret_and_caller_ip_are_sent_to_google_and_the_secret_never_returns(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::LOGIN, 'score' => 0.9]);

        $this->login(['recaptcha_token' => 'good-token'])->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === self::VERIFY_URL
                && $request['secret'] === 'test-secret-key'
                && $request['response'] === 'good-token'
                && ! empty($request['remoteip']);
        });
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

        // Deliberate: an outage at Google must not take sign-in down with it.
        // The rate limiters stay in force regardless.
        $this->login(['recaptcha_token' => 'good-token'])->assertOk();
    }

    public function test_verification_fails_open_on_a_server_error_from_google(): void
    {
        $this->enableRecaptcha();
        Http::fake([self::VERIFY_URL => Http::response('', 503)]);

        $this->login(['recaptcha_token' => 'good-token'])->assertOk();
    }

    // -----------------------------------------------------------------
    // Coverage of the flows named in the requirement
    // -----------------------------------------------------------------

    public function test_every_required_flow_endpoint_carries_the_recaptcha_middleware(): void
    {
        $expected = [
            'POST api/register' => RecaptchaAction::REGISTER,
            'POST api/login' => RecaptchaAction::LOGIN,
            'POST api/otp/resend' => RecaptchaAction::OTP_RESEND,
            'POST api/2fa/challenge' => RecaptchaAction::TWO_FACTOR_CHALLENGE,
            'POST api/2fa/enable' => RecaptchaAction::TWO_FACTOR_ENABLE,
            'POST api/password/forgot' => RecaptchaAction::PASSWORD_FORGOT,
            'POST api/password/reset' => RecaptchaAction::PASSWORD_RESET,
            'POST api/user/password/request-code' => RecaptchaAction::PASSWORD_CHANGE,
            'POST api/user/password' => RecaptchaAction::PASSWORD_CHANGE,
        ];

        $actual = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'recaptcha:')) {
                    $actual[$route->methods()[0].' '.$route->uri()] = substr($middleware, strlen('recaptcha:'));
                }
            }
        }

        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_each_route_enforces_its_own_action_not_a_shared_one(): void
    {
        $this->enableRecaptcha();

        // A valid, high-scoring token - but minted on the login form. Recovery
        // must not accept it.
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::LOGIN, 'score' => 0.9]);

        $this->postJson('/api/password/forgot', [
            'identifier' => $this->user->email,
            'recaptcha_token' => 'login-token',
        ])->assertStatus(422)->assertJsonPath('reason', 'action_mismatch');
    }

    public function test_a_route_accepts_a_token_minted_under_its_own_action(): void
    {
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'action' => RecaptchaAction::PASSWORD_FORGOT, 'score' => 0.9]);

        $this->postJson('/api/password/forgot', [
            'identifier' => $this->user->email,
            'recaptcha_token' => 'forgot-token',
        ])->assertOk();
    }
}
