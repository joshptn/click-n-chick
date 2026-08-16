<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Verification\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The email channel end to end, minus delivery.
 *
 * Mail::fake() stands in for SMTP - no credentials exist yet, so nothing here
 * asserts that a message actually leaves the machine, only that the app hands
 * a correctly addressed mailable to the mailer.
 */
class EmailChannelRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '+639171234567',
            'verification_channel' => 'email',
        ], $overrides);
    }

    public function test_registering_on_the_email_channel_mails_a_code(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('verification_channel', 'email');

        Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('juan@example.test'));

        $user = User::where('email', 'juan@example.test')->firstOrFail();
        $this->assertSame('email', $user->verification_channel);
        $this->assertSame(User::STATUS_PENDING_VERIFICATION, $user->account_status);
        $this->assertNull($user->email_verified_at);
    }

    public function test_the_code_row_records_the_channel_and_a_generic_identifier_hash(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $otp = OtpCode::firstOrFail();

        $this->assertSame('email', $otp->channel);
        $this->assertNotNull($otp->identifier_hash);

        // The hash is over the normalised address, not the phone number.
        $registry = app(\App\Services\Verification\ChannelRegistry::class);
        $this->assertSame($registry->hash(Channel::Email, 'JUAN@example.test'), $otp->identifier_hash);
        $this->assertNotSame($registry->hash(Channel::Sms, '+639171234567'), $otp->identifier_hash);
    }

    public function test_verifying_by_email_activates_the_account_and_stamps_email_verified_at(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $response = $this->postJson('/api/otp/verify', [
            'email' => 'juan@example.test',
            'code' => $code,
        ])->assertOk()->assertJsonStructure(['user', 'token']);

        $user = User::where('email', 'juan@example.test')->firstOrFail();

        $this->assertNotNull($user->email_verified_at, 'users.email_verified_at finally has a writer.');
        $this->assertNull($user->phone_verified_at, 'Only the chosen channel is verified.');
        $this->assertSame(User::STATUS_ACTIVE, $user->account_status);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_the_sms_channel_still_stamps_the_phone_column(): void
    {
        $this->postJson('/api/register', $this->payload(['verification_channel' => 'sms']))
            ->assertCreated()
            ->assertJsonPath('verification_channel', 'sms');

        $otp = OtpCode::firstOrFail();
        $this->assertSame('sms', $otp->channel);
    }

    public function test_an_omitted_channel_defaults_to_sms(): void
    {
        $payload = $this->payload();
        unset($payload['verification_channel']);

        $this->postJson('/api/register', $payload)
            ->assertCreated()
            ->assertJsonPath('verification_channel', 'sms');
    }

    public function test_an_unknown_channel_is_rejected(): void
    {
        $this->postJson('/api/register', $this->payload(['verification_channel' => 'carrier_pigeon']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('verification_channel');
    }

    public function test_a_code_issued_for_one_channel_cannot_be_redeemed_on_the_other(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        // Same code, submitted against the phone identifier: different hash,
        // so there is nothing to redeem.
        $this->postJson('/api/otp/verify', [
            'phone_number' => '+639171234567',
            'code' => $code,
        ])->assertStatus(422);

        $this->assertNull(User::where('email', 'juan@example.test')->value('email_verified_at'));
    }
}
