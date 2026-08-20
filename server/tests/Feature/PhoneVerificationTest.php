<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Captures outbound SMS so the code can be read back in assertions. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here registers and verifies over the phone channel, which
        // the app only offers where a provider is configured.
        $this->enableSmsChannel();

        $this->sent = [];

        $this->app->bind(SmsSender::class, fn () => new class($this->sent) implements SmsSender {
            public function __construct(private array &$sent)
            {
            }

            public function send(string $to, string $message, ?string $otpCode = null): void
            {
                $this->sent[] = [
                    'to' => $to,
                    'template' => $message,
                    'code' => $otpCode,
                    // What the recipient actually reads, once the provider
                    // substitutes {otp} from the code parameter.
                    'message' => $otpCode === null ? $message : str_replace('{otp}', $otpCode, $message),
                ];
            }
        });
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '+639171234567',
        ], $overrides);
    }

    private function lastCode(): string
    {
        $this->assertNotEmpty($this->sent, 'No SMS was sent.');
        preg_match('/\b(\d{6})\b/', end($this->sent)['message'], $m);

        return $m[1];
    }

    // -----------------------------------------------------------------
    // Registration is blocking
    // -----------------------------------------------------------------

    public function test_registration_creates_a_pending_row_and_issues_no_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $response->assertJsonPath('status', 'pending_verification');
        $response->assertJsonMissingPath('token');
        $response->assertJsonMissingPath('user');

        $user = User::where('email', 'juan@example.test')->firstOrFail();

        $this->assertSame(User::STATUS_PENDING_VERIFICATION, $user->account_status);
        $this->assertNull($user->phone_verified_at);
        $this->assertSame(0, $user->tokens()->count(), 'A pending signup must hold no token.');
        $this->assertCount(1, $this->sent);
    }

    public function test_the_sms_carries_a_six_digit_code_and_no_link(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $message = end($this->sent)['message'];

        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $message);
        $this->assertStringNotContainsStringIgnoringCase('http', $message);
        $this->assertStringNotContainsString('://', $message);
        $this->assertStringNotContainsString('www.', $message);
    }

    public function test_the_code_travels_separately_from_the_template(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $sent = end($this->sent);

        // Semaphore substitutes {otp} from the `code` parameter. If the template
        // arrived already interpolated, Semaphore would append a second code of
        // its own and the user would receive two.
        $this->assertStringContainsString('{otp}', $sent['template']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $sent['code']);
        $this->assertStringNotContainsString($sent['code'], $sent['template']);
    }

    public function test_the_message_fits_a_single_160_character_segment(): void
    {
        // Semaphore's OTP route bills 2 credits per 160 characters, so a second
        // segment doubles the cost of every send.
        $this->assertLessThanOrEqual(
            OtpService::MAX_MESSAGE_LENGTH,
            OtpService::renderedMessageLength(),
            'The OTP message spilled into a second SMS segment.'
        );
    }

    public function test_a_pending_account_cannot_log_in(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/login', ['login' => 'juan@example.test', 'password' => 'Password123!'])
            ->assertStatus(403)
            ->assertJsonPath('status', 'pending_verification')
            ->assertJsonMissingPath('token');
    }

    // -----------------------------------------------------------------
    // Duplicate submission resends instead of erroring or duplicating
    // -----------------------------------------------------------------

    public function test_resubmitting_the_same_registration_resends_rather_than_duplicating(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $firstCode = $this->lastCode();

        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->assertSame(1, User::where('email', 'juan@example.test')->count(), 'No duplicate row.');
        $this->assertCount(2, $this->sent, 'The second submission must resend.');
        $this->assertNotSame($firstCode, $this->lastCode(), 'A fresh code must be issued.');

        // The superseded code must no longer work.
        $this->postJson('/api/otp/verify', ['phone_number' => '+639171234567', 'code' => $firstCode])
            ->assertStatus(422);
    }

    public function test_a_matching_phone_with_a_different_email_is_also_treated_as_a_resend(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/register', $this->payload(['email' => 'different@example.test']))
            ->assertStatus(201);

        $this->assertSame(1, User::where('phone_number_hash', User::hashPhoneNumber('+639171234567'))->count());
        $this->assertSame('different@example.test', User::first()->email, 'Details refresh from the newer attempt.');
    }

    public function test_an_abandoned_pending_row_is_taken_over_by_a_new_attempt(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $original = User::firstOrFail();
        $original->forceFill([
            'created_at' => now()->subHours(User::PENDING_VERIFICATION_HOURS + 1),
        ])->save();

        $this->postJson('/api/register', $this->payload(['first_name' => 'Maria']))->assertStatus(201);

        $this->assertSame(1, User::count());
        $this->assertNotSame($original->id, User::first()->id, 'The abandoned row is replaced, not reused.');
        $this->assertSame('Maria', User::first()->first_name);
    }

    public function test_a_verified_account_still_blocks_a_duplicate_signup(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $this->postJson('/api/otp/verify', [
            'phone_number' => '+639171234567',
            'code' => $this->lastCode(),
        ])->assertOk();

        $this->postJson('/api/register', $this->payload(['phone_number' => '+639998887777']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/register', $this->payload(['email' => 'other@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');
    }

    // -----------------------------------------------------------------
    // Verification
    // -----------------------------------------------------------------

    public function test_verifying_activates_the_account_and_returns_a_session(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $response = $this->postJson('/api/otp/verify', [
            'phone_number' => '+639171234567',
            'code' => $this->lastCode(),
        ])->assertOk();

        // Same shape login returns, so the client needs no follow-up call.
        $response->assertJsonStructure(['user' => ['id', 'email', 'role'], 'token']);

        $user = User::firstOrFail();
        $this->assertSame(User::STATUS_ACTIVE, $user->account_status);
        $this->assertNotNull($user->phone_verified_at);

        // And the account can now log in normally.
        $this->postJson('/api/login', ['login' => 'juan@example.test', 'password' => 'Password123!'])
            ->assertOk();
    }

    public function test_a_code_is_single_use(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $code = $this->lastCode();

        $this->postJson('/api/otp/verify', ['phone_number' => '+639171234567', 'code' => $code])->assertOk();
        $this->postJson('/api/otp/verify', ['phone_number' => '+639171234567', 'code' => $code])->assertStatus(422);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $code = $this->lastCode();

        OtpCode::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/otp/verify', ['phone_number' => '+639171234567', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'expired');

        $this->assertSame(User::STATUS_PENDING_VERIFICATION, User::first()->account_status);
    }

    public function test_a_code_burns_after_the_attempt_cap(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $code = $this->lastCode();

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/otp/verify', ['phone_number' => '+639171234567', 'code' => '000000'])
                ->assertStatus(422);
        }

        // Even the correct code is now dead.
        $this->postJson('/api/otp/verify', ['phone_number' => '+639171234567', 'code' => $code])
            ->assertStatus(422);

        $this->assertSame(User::STATUS_PENDING_VERIFICATION, User::first()->account_status);
    }

    public function test_the_lookup_is_by_hash_so_an_unknown_number_never_matches(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/otp/verify', ['phone_number' => '+639998887777', 'code' => $this->lastCode()])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Resend
    // -----------------------------------------------------------------

    public function test_resend_is_refused_inside_the_cooldown(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/otp/resend', ['phone_number' => '+639171234567'])
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'resend_available_in']);

        $this->assertCount(1, $this->sent, 'No SMS may be sent inside the cooldown.');
    }

    public function test_resend_issues_a_new_code_once_the_cooldown_lapses(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $firstCode = $this->lastCode();

        OtpCode::query()->update([
            'created_at' => now()->subSeconds(OtpService::RESEND_COOLDOWN_SECONDS + 5),
        ]);

        $this->postJson('/api/otp/resend', ['phone_number' => '+639171234567'])->assertOk();

        $this->assertCount(2, $this->sent);
        $this->assertNotSame($firstCode, $this->lastCode());
    }

    public function test_resend_for_an_unknown_number_looks_identical_and_sends_nothing(): void
    {
        $response = $this->postJson('/api/otp/resend', ['phone_number' => '+639998887777'])->assertOk();

        $response->assertJsonStructure(['message', 'resend_available_in']);
        $this->assertCount(0, $this->sent);
    }

    // -----------------------------------------------------------------
    // Purge command
    // -----------------------------------------------------------------

    public function test_the_purge_command_only_removes_rows_past_the_window(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $fresh = User::firstOrFail();

        $stale = User::factory()->create([
            'email' => 'stale@example.test',
            'account_status' => User::STATUS_PENDING_VERIFICATION,
        ]);
        $stale->forceFill(['created_at' => now()->subHours(User::PENDING_VERIFICATION_HOURS + 1)])->save();

        $active = User::factory()->create([
            'email' => 'active@example.test',
            'account_status' => User::STATUS_ACTIVE,
        ]);
        $active->forceFill(['created_at' => now()->subYear()])->save();

        $this->artisan('registrations:purge-abandoned')->assertSuccessful();

        $this->assertNotNull($fresh->fresh(), 'A pending row inside the window survives.');
        $this->assertNull($stale->fresh(), 'A pending row past the window is deleted.');
        $this->assertNotNull($active->fresh(), 'An active account is never touched.');
    }

    public function test_the_purge_dry_run_deletes_nothing(): void
    {
        $stale = User::factory()->create(['account_status' => User::STATUS_PENDING_VERIFICATION]);
        $stale->forceFill(['created_at' => now()->subHours(User::PENDING_VERIFICATION_HOURS + 1)])->save();

        $this->artisan('registrations:purge-abandoned --dry-run')->assertSuccessful();

        $this->assertNotNull($stale->fresh());
    }
}
