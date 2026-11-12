<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Mail\VerificationCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\PendingRegistration;
use App\Services\Otp\OtpService;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sign-up with an email address, end to end, minus delivery.
 *
 * One identifier is collected and verified; no account exists until the code
 * comes back (BR-02). Mail::fake() stands in for the mailer, so nothing here
 * asserts that a message leaves the machine, only that a correctly addressed
 * mailable is handed over.
 */
class EmailChannelRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'identifier' => 'juan@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
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

    public function test_signing_up_with_an_email_mails_a_code_and_creates_no_account_yet(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', AuthController::STATUS_PENDING_VERIFICATION)
            ->assertJsonPath('verification_channel', 'email')
            ->assertJsonPath('identifier', 'ju***@example.test')
            ->assertJsonPath('resend_available_in', OtpService::RESEND_COOLDOWN_SECONDS)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('juan@example.test'));

        $this->assertDatabaseCount('users', 0);
        $this->assertNotNull(app(PendingRegistration::class)->get(Channel::Email, 'juan@example.test'));
    }

    public function test_the_code_row_records_the_channel_and_a_generic_identifier_hash_with_no_owner(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $otp = OtpCode::firstOrFail();

        $this->assertSame('email', $otp->channel);
        $this->assertNull($otp->user_id, 'No account exists yet to own the code.');

        // The hash is over the normalised address, whatever case was typed.
        $registry = app(ChannelRegistry::class);
        $this->assertSame($registry->hash(Channel::Email, 'JUAN@example.test'), $otp->identifier_hash);
    }

    public function test_verifying_creates_the_account_verified_and_signed_in(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $response = $this->postJson('/api/otp/verify', [
            'identifier' => 'juan@example.test',
            'code' => $this->lastMailedCode(),
        ])->assertOk()->assertJsonStructure(['user' => ['id', 'email', 'role'], 'token', 'device_id']);

        $user = User::where('email', 'juan@example.test')->firstOrFail();

        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->phone_number, 'Only the identifier given is stored.');
        $this->assertNull($user->phone_verified_at);
        $this->assertSame('email', $user->verification_channel);
        $this->assertSame(User::STATUS_ACTIVE, $user->account_status);
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertSame('Juan', $user->first_name);
        $this->assertNotEmpty($response->json('token'));

        // The submission is gone from the cache, and the account signs in.
        $this->assertNull(app(PendingRegistration::class)->get(Channel::Email, 'juan@example.test'));
        $this->postJson('/api/login', ['login' => 'juan@example.test', 'password' => 'Password123!'])->assertOk();
    }

    public function test_the_address_is_stored_and_matched_without_regard_to_case(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload(['identifier' => ' Juan@Example.TEST ']))
            ->assertCreated()
            ->assertJsonPath('identifier', 'ju***@example.test');

        Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('juan@example.test'));

        $this->postJson('/api/otp/verify', ['identifier' => 'JUAN@EXAMPLE.TEST', 'code' => $this->lastMailedCode()])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'juan@example.test']);

        // The same address in another case is the same account, not a new one.
        $this->postJson('/api/register', $this->payload(['identifier' => 'JUAN@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('identifier');

        $this->postJson('/api/login', ['login' => 'Juan@Example.Test', 'password' => 'Password123!'])->assertOk();
    }

    public function test_a_phone_identifier_is_refused_while_sms_cannot_deliver(): void
    {
        // SMS_DRIVER stays at its 'log' default and this is not a local
        // machine, so sms cannot deliver. Refused outright rather than parked
        // behind a code nobody receives.
        $this->postJson('/api/register', $this->payload(['identifier' => '+639171234567']))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'channel_unavailable');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_a_phone_identifier_works_on_a_local_machine_with_the_code_in_the_log(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->postJson('/api/register', $this->payload(['identifier' => '0917 123 4567']))
            ->assertCreated()
            ->assertJsonPath('verification_channel', 'sms');

        $this->assertSame('sms', OtpCode::firstOrFail()->channel);
    }

    public function test_people_sharing_one_address_can_each_sign_up(): void
    {
        Mail::fake();

        // One household, school or carrier address: more than the old
        // five-per-address allowance, each with their own email.
        foreach (range(1, 8) as $n) {
            $this->postJson('/api/register', $this->payload(['identifier' => "person{$n}@example.test"]))->assertCreated();
        }

        Mail::assertSent(VerificationCodeMail::class, 8);
    }

    public function test_a_refused_code_request_says_how_many_minutes_to_wait(): void
    {
        // Three per identifier a quarter hour; the fourth is the limiter's.
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/otp/resend', ['identifier' => 'nobody@example.test'])->assertOk();
        }

        $this->postJson('/api/otp/resend', ['identifier' => 'nobody@example.test'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many requests. Please try again in 15 minutes.')
            ->assertJsonPath('retry_after', fn ($wait) => $wait > 840 && $wait <= 900);
    }

    public function test_a_phone_identifier_goes_over_sms_once_a_provider_is_configured(): void
    {
        $this->enableSmsChannel();

        $this->postJson('/api/register', $this->payload(['identifier' => '0917 123 4567']))
            ->assertCreated()
            ->assertJsonPath('verification_channel', 'sms')
            ->assertJsonPath('identifier', '+639******567');

        $this->assertSame('sms', OtpCode::firstOrFail()->channel);
    }

    public function test_an_identifier_that_is_neither_is_rejected(): void
    {
        // Two, not more: the otp-send limiter allows three attempts a quarter
        // hour per identifier, and junk identifiers all share one key.
        foreach (['carrier pigeon', 'juan@'] as $junk) {
            $this->postJson('/api/register', $this->payload(['identifier' => $junk]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('identifier');
        }

        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_a_code_issued_for_one_identifier_cannot_be_redeemed_on_another(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();
        $code = $this->lastMailedCode();

        $this->postJson('/api/otp/verify', ['identifier' => 'other@example.test', 'code' => $code])->assertStatus(422);
        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $code])->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_signing_up_again_inside_the_cooldown_replaces_the_submission_without_another_code(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();
        $code = $this->lastMailedCode();

        $this->postJson('/api/register', $this->payload(['first_name' => 'Maria']))
            ->assertCreated()
            ->assertJsonPath('resend_available_in', fn ($wait) => $wait > 0 && $wait <= OtpService::RESEND_COOLDOWN_SECONDS);

        Mail::assertSent(VerificationCodeMail::class, 1);

        // The first code still works, and it is the newer details that become the account.
        $this->postJson('/api/otp/verify', ['identifier' => 'juan@example.test', 'code' => $code])->assertOk();
        $this->assertSame('Maria', User::where('email', 'juan@example.test')->value('first_name'));
    }

    public function test_signing_up_again_after_the_cooldown_sends_a_fresh_code_and_retires_the_old_one(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();
        $first = $this->lastMailedCode();

        OtpCode::query()->update(['created_at' => now()->subSeconds(OtpService::RESEND_COOLDOWN_SECONDS + 5)]);

        $this->postJson('/api/register', $this->payload())->assertCreated();
        Mail::assertSent(VerificationCodeMail::class, 2);

        $this->postJson('/api/otp/verify', ['identifier' => 'juan@example.test', 'code' => $first])->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_right_code_whose_submission_has_lapsed_asks_for_a_new_sign_up(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();
        $code = $this->lastMailedCode();

        // The cache entry is gone (a restart, or the window passed) while the
        // code itself is still inside its ten minutes.
        Cache::flush();

        $this->postJson('/api/otp/verify', ['identifier' => 'juan@example.test', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'registration_expired');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_first_to_verify_an_identifier_gets_it(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();
        $code = $this->lastMailedCode();

        // Somebody else took the address between the sign-up and the code.
        User::factory()->create(['email' => 'juan@example.test']);

        $this->postJson('/api/otp/verify', ['identifier' => 'juan@example.test', 'code' => $code])->assertStatus(422);
        $this->assertSame(1, User::where('email', 'juan@example.test')->count());
    }

    public function test_a_registered_address_cannot_sign_up_again(): void
    {
        User::factory()->create(['email' => 'juan@example.test']);

        $this->postJson('/api/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('identifier');

        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_resend_sends_a_fresh_code_for_a_pending_sign_up_and_nothing_for_an_unknown_one(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();
        $first = $this->lastMailedCode();

        $this->postJson('/api/otp/resend', ['identifier' => 'juan@example.test'])->assertStatus(429);

        OtpCode::query()->update(['created_at' => now()->subSeconds(OtpService::RESEND_COOLDOWN_SECONDS + 5)]);

        $this->postJson('/api/otp/resend', ['identifier' => 'juan@example.test'])->assertOk();
        Mail::assertSent(VerificationCodeMail::class, 2);

        $this->postJson('/api/otp/resend', ['identifier' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonStructure(['message', 'resend_available_in']);
        Mail::assertSent(VerificationCodeMail::class, 2);

        $this->postJson('/api/otp/verify', ['identifier' => 'juan@example.test', 'code' => $first])->assertStatus(422);
        $this->postJson('/api/otp/verify', ['identifier' => 'juan@example.test', 'code' => $this->lastMailedCode()])->assertOk();
    }

    public function test_the_old_field_names_are_still_understood_on_the_code_endpoints(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $this->postJson('/api/otp/verify', ['email' => 'juan@example.test', 'code' => $this->lastMailedCode()])->assertOk();
    }
}
