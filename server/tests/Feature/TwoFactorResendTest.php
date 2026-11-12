<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Another code for a sign-in challenge already issued (2FA or the reCAPTCHA
 * step-up): POST /api/2fa/resend.
 */
class TwoFactorResendTest extends TestCase
{
    use RefreshDatabase;

    private function twoFactorUser(): User
    {
        return User::factory()->create([
            'email' => 'two@example.test',
            'password' => Hash::make('Password123!'),
            'verification_channel' => 'email',
            'account_status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
            'two_factor_channel' => 'email',
            'two_factor_confirmed_at' => now(),
        ])->fresh();
    }

    private function challenge(): string
    {
        $response = $this->postJson('/api/login', ['login' => 'two@example.test', 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true);

        return $response->json('challenge_token');
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

    public function test_a_resend_inside_the_cooldown_is_refused_and_sends_nothing(): void
    {
        Mail::fake();
        $this->twoFactorUser();
        $token = $this->challenge();

        $this->postJson('/api/2fa/resend', ['challenge_token' => $token])
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'resend_available_in']);

        Mail::assertSent(VerificationCodeMail::class, 1);
    }

    public function test_a_resend_after_the_cooldown_issues_a_fresh_code_for_the_same_challenge(): void
    {
        Mail::fake();
        $this->twoFactorUser();
        $token = $this->challenge();
        $first = $this->lastMailedCode();

        OtpCode::query()->update(['created_at' => now()->subSeconds(OtpService::RESEND_COOLDOWN_SECONDS + 5)]);

        $this->postJson('/api/2fa/resend', ['challenge_token' => $token])
            ->assertOk()
            ->assertJsonPath('resend_available_in', OtpService::RESEND_COOLDOWN_SECONDS);

        Mail::assertSent(VerificationCodeMail::class, 2);
        $second = $this->lastMailedCode();
        $this->assertNotSame($first, $second);

        // The old code is retired; the new one completes the same challenge.
        $this->postJson('/api/2fa/challenge', ['challenge_token' => $token, 'code' => $first])->assertStatus(422);
        $this->postJson('/api/2fa/challenge', ['challenge_token' => $token, 'code' => $second])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_an_unknown_or_spent_challenge_token_is_reported_as_expired(): void
    {
        Mail::fake();
        $this->twoFactorUser();

        $this->postJson('/api/2fa/resend', ['challenge_token' => str_repeat('x', 48)])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_expired');

        $token = $this->challenge();
        $this->postJson('/api/2fa/challenge', ['challenge_token' => $token, 'code' => $this->lastMailedCode()])->assertOk();

        $this->postJson('/api/2fa/resend', ['challenge_token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_expired');

        Mail::assertSent(VerificationCodeMail::class, 1);
    }

    public function test_resends_are_limited_per_challenge(): void
    {
        Mail::fake();
        $this->twoFactorUser();
        $token = $this->challenge();

        // Three attempts a quarter hour against one challenge, whatever the
        // cooldown says about each of them: the first three are answered by
        // the cooldown, the fourth by the limiter.
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/2fa/resend', ['challenge_token' => $token])
                ->assertStatus(429)
                ->assertJsonStructure(['resend_available_in']);
        }

        $this->postJson('/api/2fa/resend', ['challenge_token' => $token])
            ->assertStatus(429)
            ->assertJsonMissingPath('resend_available_in');
    }
}
