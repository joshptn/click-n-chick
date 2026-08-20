<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Identity step-up after a low reCAPTCHA score at login (FR-01.15, BR-35).
 *
 * A low score means a real browser that looked suspicious, so the requester
 * proves themselves with an OTP instead of being turned away. Every other
 * reCAPTCHA failure is still a hard refusal - see the boundary tests at the
 * bottom, which are the ones that stop this becoming a free OTP dispenser.
 */
class RecaptchaStepUpTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private int $phoneSeq = 0;

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

    /** A verdict Google would return for a real browser that scored badly. */
    private function fakeLowScore(): void
    {
        $this->fakeVerdict(['success' => true, 'action' => 'login', 'score' => 0.1]);
    }

    /**
     * Derive the faked verdict from the submitted token, so one fake serves a
     * multi-step flow.
     *
     * A step-up spans two guarded endpoints with different actions - login,
     * then the challenge - and a single fixed verdict would fail the action
     * check on whichever one it was not minted for. Tokens are written
     * "action@score".
     */
    private function fakeVerdictsFromToken(): void
    {
        Http::fake(function ($request) {
            [$action, $score] = array_pad(explode('@', (string) ($request['response'] ?? ''), 2), 2, '0.9');

            return Http::response([
                'success' => true,
                'action' => $action,
                'score' => (float) $score,
            ], 200);
        });
    }

    private function customer(array $overrides = []): User
    {
        $phone = '+6391716'.str_pad((string) (++$this->phoneSeq), 5, '0', STR_PAD_LEFT);

        return User::factory()->create(array_merge([
            'password' => Hash::make(self::PASSWORD),
            'role' => User::ROLE_CUSTOMER,
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => 'email',
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ], $overrides))->fresh();
    }

    private function login(User $user, array $extra = [])
    {
        return $this->postJson('/api/login', array_merge([
            'login' => $user->email,
            'password' => self::PASSWORD,
        ], $extra));
    }

    private function lastMailedCode(): string
    {
        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    // -----------------------------------------------------------------
    // The step-up itself
    // -----------------------------------------------------------------

    public function test_a_low_score_issues_an_otp_challenge_instead_of_a_token(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeLowScore();

        $user = $this->customer();

        $response = $this->login($user, ['recaptcha_token' => 'real-but-suspicious'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('reason', 'recaptcha_step_up');

        $this->assertNotEmpty($response->json('challenge_token'));
        // The whole point: no session until the human is proven.
        $this->assertNull($response->json('token'));

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => OtpCode::PURPOSE_STEP_UP,
        ]);
    }

    public function test_answering_the_step_up_completes_the_login(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeVerdictsFromToken();

        $user = $this->customer();

        $challenge = $this->login($user, ['recaptcha_token' => 'login@0.1'])->json('challenge_token');

        $this->postJson('/api/2fa/challenge', [
            'challenge_token' => $challenge,
            'code' => $this->lastMailedCode(),
            'recaptcha_token' => 'two_factor_challenge@0.9',
        ])
            ->assertOk()
            ->assertJsonStructure(['user', 'token', 'device_id']);
    }

    /**
     * The account has no 2FA enabled - that is exactly when a step-up happens.
     * The challenge endpoint used to require 2FA to be on, which would have
     * made every step-up unredeemable.
     */
    public function test_the_step_up_works_on_an_account_without_two_factor(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeVerdictsFromToken();

        $user = $this->customer();
        $this->assertFalse($user->hasTwoFactorEnabled());

        $challenge = $this->login($user, ['recaptcha_token' => 'login@0.1'])->json('challenge_token');

        $this->postJson('/api/2fa/challenge', [
            'challenge_token' => $challenge,
            'code' => $this->lastMailedCode(),
            'recaptcha_token' => 'two_factor_challenge@0.9',
        ])->assertOk();
    }

    public function test_a_wrong_step_up_code_does_not_issue_a_session(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeLowScore();

        $user = $this->customer();
        $this->fakeVerdictsFromToken();
        $challenge = $this->login($user, ['recaptcha_token' => 'login@0.1'])->json('challenge_token');

        $this->postJson('/api/2fa/challenge', [
            'challenge_token' => $challenge,
            'code' => '000000',
            'recaptcha_token' => 'two_factor_challenge@0.9',
        ])->assertStatus(422);

        $this->assertSame(0, $user->tokens()->count());
    }

    // -----------------------------------------------------------------
    // What a low score must NOT do
    // -----------------------------------------------------------------

    /**
     * The step-up must sit behind the password check. Issuing it first would
     * both leak which accounts exist and send codes to people who are merely
     * being guessed at.
     */
    public function test_wrong_credentials_with_a_low_score_still_just_fail(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeLowScore();

        $user = $this->customer();

        $this->login($user, ['password' => 'WrongPassword1!', 'recaptcha_token' => 'suspicious'])
            ->assertStatus(401);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('otp_codes', [
            'user_id' => $user->id,
            'purpose' => OtpCode::PURPOSE_STEP_UP,
        ]);
    }

    public function test_an_unknown_account_with_a_low_score_sends_nothing(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeLowScore();

        $this->postJson('/api/login', [
            'login' => 'nobody@example.test',
            'password' => self::PASSWORD,
            'recaptcha_token' => 'suspicious',
        ])->assertStatus(401);

        Mail::assertNothingSent();
    }

    /** An account with 2FA on is already challenged; it must not be double-challenged. */
    public function test_two_factor_takes_precedence_over_the_step_up(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeLowScore();

        $user = $this->customer([
            'two_factor_enabled' => true,
            'two_factor_channel' => 'email',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->login($user, ['recaptcha_token' => 'suspicious'])
            ->assertOk()
            ->assertJsonPath('reason', 'two_factor');

        $this->assertDatabaseMissing('otp_codes', [
            'user_id' => $user->id,
            'purpose' => OtpCode::PURPOSE_STEP_UP,
        ]);
    }

    /**
     * The cost guard. A low score is something an attacker holding valid
     * credentials can produce at will; re-sending on every attempt would let
     * them burn SMS credits.
     */
    public function test_repeated_low_score_logins_do_not_resend_within_the_cooldown(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeLowScore();

        $user = $this->customer();

        $this->login($user, ['recaptcha_token' => 'suspicious'])->assertOk();
        $this->login($user, ['recaptcha_token' => 'suspicious'])->assertOk();
        $this->login($user, ['recaptcha_token' => 'suspicious'])->assertOk();

        Mail::assertSentCount(1);
    }

    // -----------------------------------------------------------------
    // Every other failure is still a refusal
    // -----------------------------------------------------------------

    public function test_a_missing_token_is_refused_rather_than_stepped_up(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        Http::fake();

        $user = $this->customer();

        $this->login($user)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RECAPTCHA_FAILED')
            ->assertJsonPath('reason', 'missing');

        Mail::assertNothingSent();
    }

    public function test_an_invalid_token_is_refused_rather_than_stepped_up(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->login($this->customer(), ['recaptcha_token' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid');

        Mail::assertNothingSent();
    }

    public function test_a_token_minted_for_another_action_is_refused_rather_than_stepped_up(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'action' => 'register', 'score' => 0.9]);

        $this->login($this->customer(), ['recaptcha_token' => 'wrong-form'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'action_mismatch');

        Mail::assertNothingSent();
    }

    /**
     * A client must not be able to talk itself into a step-up, nor out of one.
     * The middleware writes the marker into the request attribute bag, which
     * input cannot reach.
     */
    public function test_a_client_cannot_forge_the_low_score_marker(): void
    {
        Mail::fake();
        $this->enableRecaptcha();
        $this->fakeVerdict(['success' => true, 'action' => 'login', 'score' => 0.9]);

        $user = $this->customer();

        $response = $this->login($user, [
            'recaptcha_token' => 'good',
            'recaptcha.low_score' => true,
            'recaptcha_low_score' => true,
        ])->assertOk();

        // Scored fine, so a session is issued and no code is sent.
        $this->assertNotEmpty($response->json('token'));
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Unconfigured deployments are unaffected
    // -----------------------------------------------------------------

    public function test_login_is_unchanged_while_recaptcha_is_unconfigured(): void
    {
        Mail::fake();
        Http::fake();

        $response = $this->login($this->customer())->assertOk();

        $this->assertNotEmpty($response->json('token'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
