<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Channel-specific gating, 2FA, BR-33 and the SMS availability flag.
 *
 * No delivery is exercised anywhere: Mail::fake() stands in for SMTP and the
 * SMS binding resolves to LogSmsSender under test.
 */
class ChannelGatingAndTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /** Distinct per call: phone_number_hash is uniquely indexed. */
    private int $phoneSeq = 0;

    private function verifiedUser(string $channel = 'sms'): User
    {
        $phone = '+6391712'.str_pad((string) (++$this->phoneSeq), 5, '0', STR_PAD_LEFT);

        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => $channel,
            'account_status' => User::STATUS_ACTIVE,
            'phone_verified_at' => $channel === 'sms' ? now() : null,
            'email_verified_at' => $channel === 'email' ? now() : null,
        ]);

        return $user->fresh();
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
    // Part 1 - gating is per channel, not on account_status
    // -----------------------------------------------------------------

    public function test_login_is_refused_when_the_chosen_channel_is_unverified_even_if_the_status_says_active(): void
    {
        // The exact drift this guards: account_status is mass-assignable, so it
        // must never be what login trusts.
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'verification_channel' => 'sms',
            'account_status' => User::STATUS_ACTIVE,
            'phone_verified_at' => null,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Password123!'])
            ->assertStatus(403)
            ->assertJsonPath('verification_channel', 'sms');
    }

    public function test_a_verified_email_does_not_satisfy_a_phone_registration(): void
    {
        $user = $this->verifiedUser('sms');
        $user->phone_verified_at = null;
        $user->email_verified_at = now();
        $user->save();

        $this->assertFalse($user->fresh()->hasVerifiedChosenChannel());
    }

    public function test_a_verified_phone_does_not_satisfy_an_email_registration(): void
    {
        $user = $this->verifiedUser('email');
        $user->email_verified_at = null;
        $user->phone_verified_at = now();
        $user->save();

        $this->assertFalse($user->fresh()->hasVerifiedChosenChannel());
    }

    public function test_login_succeeds_for_each_channel_once_its_own_timestamp_is_set(): void
    {
        foreach (['sms', 'email'] as $channel) {
            $user = $this->verifiedUser($channel);

            $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Password123!'])
                ->assertOk()
                ->assertJsonStructure(['user', 'token']);

            $user->delete();
        }
    }

    public function test_the_blocked_login_message_names_the_specific_channel(): void
    {
        $user = $this->verifiedUser('email');
        $user->email_verified_at = null;
        $user->save();

        $response = $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Password123!'])
            ->assertStatus(403);

        $message = $response->json('message');

        // Must name the channel; must not fall back to generic "verify your
        // account" wording. Mentioning the account otherwise is fine.
        $this->assertStringContainsString('email address', $message);
        $this->assertDoesNotMatchRegularExpression('/verify your account/i', $message);

        $smsUser = $this->verifiedUser('sms');
        $smsUser->phone_verified_at = null;
        $smsUser->save();

        $smsMessage = $this->postJson('/api/login', ['login' => $smsUser->email, 'password' => 'Password123!'])
            ->assertStatus(403)
            ->json('message');

        $this->assertStringContainsString('phone number', $smsMessage);
        $this->assertDoesNotMatchRegularExpression('/verify your account/i', $smsMessage);
    }

    public function test_a_registration_code_cannot_be_redeemed_on_the_unchosen_channel(): void
    {
        Mail::fake();

        // Registered on sms, so the sms code is the only one that exists. Even
        // if an email code were somehow present, the channel guard rejects it.
        $this->postJson('/api/register', [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test', 'phone_number' => '+639171234567',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'verification_channel' => 'sms',
        ])->assertCreated();

        $this->postJson('/api/otp/verify', ['email' => 'juan@example.test', 'code' => '123456'])
            ->assertStatus(422);

        $this->assertNull(User::where('email', 'juan@example.test')->value('email_verified_at'));
    }

    // -----------------------------------------------------------------
    // Part 2 - two-factor authentication
    // -----------------------------------------------------------------

    public function test_enabling_two_factor_records_the_channel_and_verifies_it(): void
    {
        Mail::fake();

        // Registered on sms, so email is not yet verified.
        $user = $this->verifiedUser('sms');
        $this->assertNull($user->email_verified_at);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/enable', ['channel' => 'email'])
            ->assertOk()
            ->assertJsonPath('channel', 'email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/confirm', ['code' => $this->lastMailedCode()])
            ->assertOk()
            ->assertJsonPath('two_factor_channel', 'email');

        $fresh = $user->fresh();

        $this->assertTrue($fresh->two_factor_enabled);
        $this->assertSame('email', $fresh->two_factor_channel);
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertNotNull($fresh->email_verified_at, 'Proving the channel verifies it.');
    }

    public function test_a_wrong_code_does_not_enable_two_factor(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('sms');

        $this->actingAs($user, 'sanctum')->postJson('/api/2fa/enable', ['channel' => 'email'])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse((bool) $user->fresh()->two_factor_enabled);
    }

    public function test_confirming_without_requesting_a_code_is_refused(): void
    {
        $user = $this->verifiedUser('sms');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/confirm', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'no_code');
    }

    public function test_login_with_two_factor_issues_a_challenge_instead_of_a_token(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('email');
        $user->two_factor_enabled = true;
        $user->two_factor_channel = 'email';
        $user->save();

        $response = $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('two_factor_channel', 'email');

        $this->assertNull($response->json('token'), 'No session token before the second factor.');
        $this->assertNotEmpty($response->json('challenge_token'));
    }

    public function test_the_challenge_uses_the_channel_fixed_at_enable_time(): void
    {
        Mail::fake();

        // Registered on sms but enrolled 2FA on email: the challenge must follow
        // the 2FA channel, not the registration channel.
        $user = $this->verifiedUser('sms');
        $user->two_factor_enabled = true;
        $user->two_factor_channel = 'email';
        $user->email_verified_at = now();
        $user->save();

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonPath('two_factor_channel', 'email');

        Mail::assertSent(VerificationCodeMail::class);

        $this->assertSame('email', OtpCode::where('purpose', OtpCode::PURPOSE_TWO_FACTOR_LOGIN)->value('channel'));
    }

    public function test_answering_the_challenge_completes_the_login(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('email');
        $user->two_factor_enabled = true;
        $user->two_factor_channel = 'email';
        $user->save();

        $challenge = $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Password123!'])
            ->assertOk()
            ->json('challenge_token');

        $this->postJson('/api/2fa/challenge', [
            'challenge_token' => $challenge,
            'code' => $this->lastMailedCode(),
        ])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_a_challenge_token_is_single_use(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('email');
        $user->two_factor_enabled = true;
        $user->two_factor_channel = 'email';
        $user->save();

        $challenge = $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Password123!'])
            ->json('challenge_token');
        $code = $this->lastMailedCode();

        $this->postJson('/api/2fa/challenge', ['challenge_token' => $challenge, 'code' => $code])->assertOk();

        $this->postJson('/api/2fa/challenge', ['challenge_token' => $challenge, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_expired');
    }

    public function test_an_unknown_challenge_token_is_refused(): void
    {
        $this->postJson('/api/2fa/challenge', ['challenge_token' => 'not-a-real-token', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'challenge_expired');
    }

    // -----------------------------------------------------------------
    // Part 3 - BR-33 password change
    // -----------------------------------------------------------------

    public function test_a_password_change_without_a_code_is_refused(): void
    {
        $user = $this->verifiedUser('email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password', [
                'current_password' => 'Password123!',
                'password' => 'Replacement1!',
                'password_confirmation' => 'Replacement1!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
    }

    public function test_a_password_change_with_a_wrong_code_is_refused(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password/request-code', ['channel' => 'email'])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password', [
                'current_password' => 'Password123!',
                'password' => 'Replacement1!',
                'password_confirmation' => 'Replacement1!',
                'code' => '000000',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
    }

    public function test_a_valid_code_and_current_password_change_it(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password/request-code', ['channel' => 'email'])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password', [
                'current_password' => 'Password123!',
                'password' => 'Replacement1!',
                'password_confirmation' => 'Replacement1!',
                'code' => $this->lastMailedCode(),
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Replacement1!', $user->fresh()->password));
    }

    public function test_the_password_change_code_is_single_use(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password/request-code', ['channel' => 'email'])->assertOk();
        $code = $this->lastMailedCode();

        $this->actingAs($user, 'sanctum')->postJson('/api/user/password', [
            'current_password' => 'Password123!',
            'password' => 'Replacement1!',
            'password_confirmation' => 'Replacement1!',
            'code' => $code,
        ])->assertOk();

        // Token was revoked by the change, so act as the user again.
        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/user/password', [
            'current_password' => 'Replacement1!',
            'password' => 'Another1Pass!',
            'password_confirmation' => 'Another1Pass!',
            'code' => $code,
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('Replacement1!', $user->fresh()->password));
    }

    public function test_the_password_change_channel_is_chosen_per_attempt_and_not_stored(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password/request-code', ['channel' => 'email'])->assertOk();

        // Nothing about the choice persists on the account, unlike 2FA's channel.
        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_channel);
        $this->assertSame('email', $fresh->verification_channel, 'The registration channel is untouched.');
    }

    // -----------------------------------------------------------------
    // Part 4 - availability reflects the SMS configuration
    // -----------------------------------------------------------------

    public function test_sms_is_unavailable_while_the_driver_is_log(): void
    {
        config(['services.sms.driver' => 'log']);

        $response = $this->getJson('/api/verification/channels')->assertOk();

        $channels = collect($response->json('channels'))->keyBy('channel');

        $this->assertFalse($channels['sms']['available']);
        $this->assertNotNull($channels['sms']['reason']);
        $this->assertTrue($channels['email']['available']);
        $this->assertNull($channels['email']['reason']);
    }

    public function test_sms_becomes_available_once_a_provider_is_configured(): void
    {
        config([
            'services.sms.driver' => 'semaphore',
            'services.semaphore.key' => 'a-real-looking-key',
        ]);

        $channels = collect($this->getJson('/api/verification/channels')->json('channels'))->keyBy('channel');

        $this->assertTrue($channels['sms']['available'], 'Flips on from configuration alone, no UI change.');
        $this->assertNull($channels['sms']['reason']);
    }

    public function test_sms_stays_unavailable_when_the_driver_is_set_but_the_key_is_missing(): void
    {
        config(['services.sms.driver' => 'semaphore', 'services.semaphore.key' => '']);

        $channels = collect($this->getJson('/api/verification/channels')->json('channels'))->keyBy('channel');

        $this->assertFalse($channels['sms']['available']);
    }

    public function test_an_unavailable_channel_is_refused_for_two_factor_enrolment(): void
    {
        config(['services.sms.driver' => 'log']);

        $user = $this->verifiedUser('email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/enable', ['channel' => 'sms'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'channel_unavailable');
    }

    public function test_an_unavailable_channel_is_refused_for_a_password_change_code(): void
    {
        config(['services.sms.driver' => 'log']);

        $user = $this->verifiedUser('email');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password/request-code', ['channel' => 'sms'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'channel_unavailable');
    }

    public function test_the_registry_reports_availability_directly(): void
    {
        config(['services.sms.driver' => 'log']);
        $registry = app(ChannelRegistry::class);

        $this->assertFalse($registry->isAvailable('sms'));
        $this->assertTrue($registry->isAvailable('email'));
    }
}
