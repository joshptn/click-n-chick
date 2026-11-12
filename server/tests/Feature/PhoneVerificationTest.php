<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Sms\SmsSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Captures outbound SMS so the code can be read back in assertions. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here signs up and verifies over the phone channel, which
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
            'identifier' => '+639171234567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    private function lastCode(): string
    {
        $this->assertNotEmpty($this->sent, 'No SMS was sent.');
        preg_match('/\b(\d{6})\b/', end($this->sent)['message'], $m);

        return $m[1];
    }

    // -----------------------------------------------------------------
    // Registration is blocking, and creates nothing until verified
    // -----------------------------------------------------------------

    public function test_signing_up_texts_a_code_and_creates_no_account_or_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $response->assertJsonPath('status', AuthController::STATUS_PENDING_VERIFICATION);
        $response->assertJsonPath('verification_channel', 'sms');
        $response->assertJsonMissingPath('token');
        $response->assertJsonMissingPath('user');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertCount(1, $this->sent);
        $this->assertSame('+639171234567', $this->sent[0]['to']);
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

    public function test_an_unverified_sign_up_cannot_log_in_because_there_is_no_account(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/login', ['login' => '+639171234567', 'password' => 'Password123!'])
            ->assertStatus(401)
            ->assertJsonMissingPath('token');
    }

    // -----------------------------------------------------------------
    // Repeat submissions follow the resend rules
    // -----------------------------------------------------------------

    public function test_resubmitting_inside_the_cooldown_sends_no_second_text(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $firstCode = $this->lastCode();

        $this->postJson('/api/register', $this->payload(['first_name' => 'Maria']))
            ->assertStatus(201)
            ->assertJsonPath('resend_available_in', fn ($wait) => $wait > 0 && $wait <= OtpService::RESEND_COOLDOWN_SECONDS);

        $this->assertCount(1, $this->sent, 'A second text inside the cooldown would spend credits for nothing.');

        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $firstCode])->assertOk();
        $this->assertSame('Maria', User::firstOrFail()->first_name, 'The newer details become the account.');
    }

    public function test_resubmitting_after_the_cooldown_sends_a_fresh_code_and_retires_the_old_one(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $firstCode = $this->lastCode();

        OtpCode::query()->update(['created_at' => now()->subSeconds(OtpService::RESEND_COOLDOWN_SECONDS + 5)]);

        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->assertCount(2, $this->sent);
        $this->assertNotSame($firstCode, $this->lastCode());

        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $firstCode])->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_verified_account_blocks_a_duplicate_signup(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $this->lastCode()])->assertOk();

        // Two more attempts: with the original sign-up that is the three the
        // otp-send limiter allows per identifier.
        foreach (['09171234567', '0917 123 4567'] as $typed) {
            $this->postJson('/api/register', $this->payload(['identifier' => $typed]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('identifier');
        }

        $this->assertCount(1, $this->sent);
    }

    // -----------------------------------------------------------------
    // Verification
    // -----------------------------------------------------------------

    public function test_verifying_creates_the_account_and_returns_a_session(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $response = $this->postJson('/api/otp/verify', [
            'identifier' => '+639171234567',
            'code' => $this->lastCode(),
        ])->assertOk();

        // Same shape login returns, so the client needs no follow-up call.
        $response->assertJsonStructure(['user' => ['id', 'role'], 'token']);

        $user = User::firstOrFail();
        $this->assertSame(User::STATUS_ACTIVE, $user->account_status);
        $this->assertSame('sms', $user->verification_channel);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->email);
        $this->assertSame(User::hashPhoneNumber('+639171234567'), $user->phone_number_hash);

        // And the account can now log in normally, however the number is typed.
        $this->postJson('/api/login', ['login' => '0917 123 4567', 'password' => 'Password123!'])
            ->assertOk();
    }

    public function test_a_code_is_single_use(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $code = $this->lastCode();

        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $code])->assertOk();
        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $code])->assertStatus(422);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $code = $this->lastCode();

        OtpCode::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'expired');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_code_burns_after_the_attempt_cap(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $code = $this->lastCode();

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => '000000'])
                ->assertStatus(422);
        }

        // Even the correct code is now dead.
        $this->postJson('/api/otp/verify', ['identifier' => '+639171234567', 'code' => $code])
            ->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_lookup_is_by_hash_so_an_unknown_number_never_matches(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/otp/verify', ['identifier' => '+639998887777', 'code' => $this->lastCode()])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Resend
    // -----------------------------------------------------------------

    public function test_resend_is_refused_inside_the_cooldown(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/otp/resend', ['identifier' => '+639171234567'])
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
        $response = $this->postJson('/api/otp/resend', ['identifier' => '+639998887777'])->assertOk();

        $response->assertJsonStructure(['message', 'resend_available_in']);
        $this->assertCount(0, $this->sent);
    }

    // -----------------------------------------------------------------
    // Spent codes are pruned
    // -----------------------------------------------------------------

    public function test_the_prune_command_removes_spent_codes_older_than_a_day_and_nothing_else(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(201);
        $fresh = OtpCode::firstOrFail();

        $old = now()->subDays(2);
        $usedOld = OtpCode::create(['channel' => 'email', 'identifier_hash' => 'a', 'code_hash' => 'x', 'purpose' => 'registration', 'expires_at' => $old->copy()->addMinutes(10), 'consumed_at' => $old]);
        $expiredOld = OtpCode::create(['channel' => 'email', 'identifier_hash' => 'b', 'code_hash' => 'x', 'purpose' => 'registration', 'expires_at' => $old]);
        $liveOld = OtpCode::create(['channel' => 'email', 'identifier_hash' => 'c', 'code_hash' => 'x', 'purpose' => 'registration', 'expires_at' => now()->addDay()]);
        $usedRecent = OtpCode::create(['channel' => 'email', 'identifier_hash' => 'd', 'code_hash' => 'x', 'purpose' => 'registration', 'expires_at' => now()->addMinutes(10), 'consumed_at' => now()]);

        OtpCode::whereIn('id', [$usedOld->id, $expiredOld->id, $liveOld->id])->update(['created_at' => $old]);

        $this->artisan('otp:prune')->expectsOutputToContain('2 code(s) deleted')->assertSuccessful();

        $this->assertNotNull($fresh->fresh(), 'A code still in play survives.');
        $this->assertNull($usedOld->fresh());
        $this->assertNull($expiredOld->fresh());
        $this->assertNotNull($liveOld->fresh(), 'An old code that has not expired is left alone.');
        $this->assertNotNull($usedRecent->fresh(), 'A recently used code stays, because the resend cooldown reads it.');
    }

    public function test_the_prune_dry_run_deletes_nothing_and_the_command_runs_daily(): void
    {
        $old = now()->subDays(2);
        $spent = OtpCode::create(['channel' => 'email', 'identifier_hash' => 'a', 'code_hash' => 'x', 'purpose' => 'registration', 'expires_at' => $old]);
        OtpCode::whereKey($spent->id)->update(['created_at' => $old]);

        $this->artisan('otp:prune --dry-run')->expectsOutputToContain('1 code(s) would be deleted')->assertSuccessful();
        $this->assertNotNull($spent->fresh());

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'otp:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}
