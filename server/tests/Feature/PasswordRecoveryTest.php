<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Password recovery: enumeration resistance, OTP discipline, password policy
 * and token revocation.
 *
 * Structural only. Mail::fake() stands in for SMTP and the SmsSender binding is
 * replaced with a recorder, so nothing is ever delivered - these assert what the
 * application does with a code, never that a code arrived.
 */
class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'Password123!';
    private const NEW_PASSWORD = 'Brand-New-Password1';

    /** Captures outbound SMS so the code can be read back in assertions. */
    private array $sent = [];

    /** Distinct per call: phone_number_hash is uniquely indexed. */
    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->sent = [];

        $this->app->bind(SmsSender::class, fn () => new class($this->sent) implements SmsSender {
            public function __construct(private array &$sent)
            {
            }

            public function send(string $to, string $message, ?string $otpCode = null): void
            {
                $this->sent[] = $otpCode === null ? $message : str_replace('{otp}', $otpCode, $message);
            }
        });
    }

    /** An account that has verified exactly the channel named. */
    private function userVerifiedOn(string $channel): User
    {
        $phone = '+6391712'.str_pad((string) (++$this->phoneSeq), 5, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'password' => Hash::make(self::OLD_PASSWORD),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => $channel,
            'account_status' => User::STATUS_ACTIVE,
            'phone_verified_at' => $channel === 'sms' ? now() : null,
            'email_verified_at' => $channel === 'email' ? now() : null,
        ])->fresh();
    }

    /** Turns the phone option on the way a provisioned Semaphore account would. */
    private function enableSms(): void
    {
        config()->set('services.sms.driver', 'semaphore');
        config()->set('services.semaphore.key', 'test-key');
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

    private function lastTextedCode(): string
    {
        $this->assertNotEmpty($this->sent, 'No SMS was sent.');
        preg_match('/\b(\d{6})\b/', end($this->sent), $m);

        return $m[1];
    }

    // -----------------------------------------------------------------
    // Enumeration resistance
    // -----------------------------------------------------------------

    public function test_the_request_response_is_identical_for_every_class_of_identifier(): void
    {
        $verified = $this->userVerifiedOn('email');

        // Registered, but this channel was never verified on the account.
        $unverified = $this->userVerifiedOn('sms');
        $this->assertNull($unverified->email_verified_at);

        $responses = [
            'verified' => $this->postJson('/api/password/forgot', ['identifier' => $verified->email]),
            'unverified channel' => $this->postJson('/api/password/forgot', ['identifier' => $unverified->email]),
            'unregistered' => $this->postJson('/api/password/forgot', ['identifier' => 'nobody@example.test']),
            'malformed' => $this->postJson('/api/password/forgot', ['identifier' => 'not-an-identifier']),
        ];

        $baseline = null;

        foreach ($responses as $label => $response) {
            $response->assertOk();

            $baseline ??= $response->json();

            // Byte-identical bodies, not merely same-shaped: any difference in
            // wording or extra key would be the oracle this defends against.
            $this->assertSame(
                $baseline,
                $response->json(),
                "The '{$label}' response differs from the others and leaks account existence."
            );
        }
    }

    public function test_no_code_is_issued_for_an_unregistered_identifier(): void
    {
        $this->postJson('/api/password/forgot', ['identifier' => 'nobody@example.test'])->assertOk();

        $this->assertSame(0, OtpCode::where('purpose', OtpCode::PURPOSE_PASSWORD_RESET)->count());
        Mail::assertNothingSent();
    }

    public function test_no_code_is_issued_when_that_channel_is_unverified_on_the_account(): void
    {
        // Registered and active, but on sms - the email address has never been
        // proven, so it must not be usable to take the account over.
        $user = $this->userVerifiedOn('sms');

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();

        $this->assertSame(0, OtpCode::where('purpose', OtpCode::PURPOSE_PASSWORD_RESET)->count());
        Mail::assertNothingSent();
    }

    public function test_a_code_is_issued_over_the_channel_the_identifier_names(): void
    {
        $user = $this->userVerifiedOn('email');

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();

        $otp = OtpCode::where('purpose', OtpCode::PURPOSE_PASSWORD_RESET)->sole();

        $this->assertSame($user->id, $otp->user_id);
        $this->assertSame('email', $otp->channel);
        Mail::assertSent(VerificationCodeMail::class);
    }

    public function test_recovery_works_over_sms_once_the_provider_is_configured(): void
    {
        $this->enableSms();

        $user = $this->userVerifiedOn('sms');

        $this->postJson('/api/password/forgot', ['identifier' => $user->phone_number])->assertOk();

        $this->assertSame('sms', OtpCode::where('purpose', OtpCode::PURPOSE_PASSWORD_RESET)->value('channel'));

        $this->postJson('/api/password/reset', [
            'identifier' => $user->phone_number,
            'code' => $this->lastTextedCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_no_code_is_issued_over_a_channel_that_cannot_deliver(): void
    {
        // SMS_DRIVER stays at its 'log' default here, so the phone option is
        // not deliverable and the request must be a silent no-op.
        $user = $this->userVerifiedOn('sms');

        $this->postJson('/api/password/forgot', ['identifier' => $user->phone_number])->assertOk();

        $this->assertSame(0, OtpCode::where('purpose', OtpCode::PURPOSE_PASSWORD_RESET)->count());
        $this->assertEmpty($this->sent);
    }

    // -----------------------------------------------------------------
    // The code is required, single-use, capped and scoped
    // -----------------------------------------------------------------

    public function test_the_reset_refuses_when_no_code_was_ever_requested(): void
    {
        $user = $this->userVerifiedOn('email');

        $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => '123456',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonPath('reason', 'no_code');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_a_wrong_code_leaves_the_password_unchanged(): void
    {
        $user = $this->userVerifiedOn('email');

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();

        $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => '000000',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonPath('reason', 'invalid');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
        $this->assertSame(1, OtpCode::where('purpose', OtpCode::PURPOSE_PASSWORD_RESET)->value('attempts'));
    }

    public function test_the_code_is_single_use(): void
    {
        $user = $this->userVerifiedOn('email');

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();

        $payload = [
            'identifier' => $user->email,
            'code' => $this->lastMailedCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->postJson('/api/password/reset', $payload)->assertOk();

        // Replaying the same code must not reset the password a second time.
        $this->postJson('/api/password/reset', array_merge($payload, [
            'password' => 'Another-Password2',
            'password_confirmation' => 'Another-Password2',
        ]))->assertStatus(422)->assertJsonPath('reason', 'no_code');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_the_code_expires(): void
    {
        $user = $this->userVerifiedOn('email');

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();
        $code = $this->lastMailedCode();

        $this->travel(OtpService::EXPIRY_MINUTES + 1)->minutes();

        $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => $code,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonPath('reason', 'expired');

        $this->travelBack();

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_attempts_against_one_code_are_capped(): void
    {
        $user = $this->userVerifiedOn('email');

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();
        $realCode = $this->lastMailedCode();

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/password/reset', [
                'identifier' => $user->email,
                'code' => '000000',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertStatus(422);
        }

        // The code is burned, so even the correct one no longer works.
        $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => $realCode,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_a_registration_code_cannot_be_redeemed_as_a_reset_code(): void
    {
        $user = $this->userVerifiedOn('email');

        // Same account, same channel, different purpose.
        app(OtpService::class)->send($user, OtpCode::PURPOSE_REGISTRATION, null, 'email');

        $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => $this->lastMailedCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonPath('reason', 'no_code');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    // -----------------------------------------------------------------
    // Password policy and token revocation
    // -----------------------------------------------------------------

    public function test_the_new_password_must_satisfy_the_strong_password_rule(): void
    {
        $user = $this->userVerifiedOn('email');

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();
        $code = $this->lastMailedCode();

        foreach (['short1A', 'alllowercase1', 'NoDigitsHere'] as $weak) {
            $this->postJson('/api/password/reset', [
                'identifier' => $user->email,
                'code' => $code,
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }

        // Rejected by validation, so the code was never redeemed and the user
        // can still complete the reset with a compliant password.
        $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => $code,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_a_successful_reset_revokes_every_existing_token(): void
    {
        $user = $this->userVerifiedOn('email');

        $stale = $user->createToken('auth_token')->plainTextToken;
        $user->createToken('auth_token');

        $this->assertSame(2, $user->tokens()->count());

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();

        $response = $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => $this->lastMailedCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        // Exactly one token survives: the fresh one just handed back.
        $this->assertSame(1, $user->tokens()->count());

        $fresh = $response->json('token');
        $this->assertNotEmpty($fresh);

        $this->withHeader('Authorization', 'Bearer '.$stale)->getJson('/api/user')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer '.$fresh)->getJson('/api/user')->assertOk();
    }

    public function test_a_two_factor_account_is_challenged_rather_than_handed_a_token(): void
    {
        $user = $this->userVerifiedOn('email');
        $user->two_factor_enabled = true;
        $user->two_factor_channel = 'email';
        $user->save();

        $this->postJson('/api/password/forgot', ['identifier' => $user->email])->assertOk();

        $response = $this->postJson('/api/password/reset', [
            'identifier' => $user->email,
            'code' => $this->lastMailedCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor_required', true);

        // The password did change - recovery succeeded - but recovering it is
        // not a substitute for the second factor.
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertNull($response->json('token'));
        $this->assertSame(0, $user->tokens()->count());
    }
}
