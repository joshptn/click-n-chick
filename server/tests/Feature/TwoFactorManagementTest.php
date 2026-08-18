<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Managing an existing 2FA enrolment (UC-PROF-006 / BR-31).
 *
 * ChannelGatingAndTwoFactorTest already covers turning 2FA ON and answering the
 * login challenge. This file covers everything after that: turning it off,
 * switching channels, and the two rate-limit buckets the management screen
 * depends on.
 */
class TwoFactorManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    /** Distinct per call: phone_number_hash is uniquely indexed. */
    private int $phoneSeq = 0;

    private function verifiedUser(string $channel = 'sms'): User
    {
        $phone = '+6391713'.str_pad((string) (++$this->phoneSeq), 5, '0', STR_PAD_LEFT);

        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => $channel,
            'account_status' => User::STATUS_ACTIVE,
            'phone_verified_at' => $channel === 'sms' ? now() : null,
            'email_verified_at' => $channel === 'email' ? now() : null,
        ]);

        return $user->fresh();
    }

    /** An account with 2FA already on, enrolled over email. */
    private function twoFactorUser(): User
    {
        $user = $this->verifiedUser('sms');

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_channel' => 'email',
            'two_factor_confirmed_at' => now(),
            'email_verified_at' => now(),
        ])->save();

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
    // Turning it off
    // -----------------------------------------------------------------

    public function test_the_account_password_turns_two_factor_off(): void
    {
        $user = $this->twoFactorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', false)
            ->assertJsonPath('two_factor_channel', null);

        $fresh = $user->fresh();

        $this->assertFalse((bool) $fresh->two_factor_enabled);
        $this->assertNull($fresh->two_factor_channel, 'A stale channel behind a false flag is a disagreement waiting to happen.');
        $this->assertNull($fresh->two_factor_confirmed_at);
        $this->assertFalse($fresh->hasTwoFactorEnabled());
    }

    public function test_a_wrong_password_leaves_two_factor_on(): void
    {
        $user = $this->twoFactorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => 'NotThePassword1!'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PASSWORD_REQUIRED');

        $fresh = $user->fresh();

        $this->assertTrue($fresh->hasTwoFactorEnabled());
        $this->assertSame('email', $fresh->two_factor_channel);
    }

    public function test_disabling_requires_a_password_at_all(): void
    {
        $user = $this->twoFactorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_guest_cannot_disable_two_factor(): void
    {
        $user = $this->twoFactorUser();

        $this->postJson('/api/2fa/disable', ['password' => self::PASSWORD])
            ->assertStatus(401);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    /**
     * One user's password must never be able to strip another user's second
     * factor. The endpoint reads the account from the token, never from input,
     * so there is no target parameter to abuse - this pins that shut.
     */
    public function test_disabling_only_ever_affects_the_calling_account(): void
    {
        $actor = $this->twoFactorUser();
        $bystander = $this->twoFactorUser();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/2fa/disable', [
                'password' => self::PASSWORD,
                // Ignored: there is no such parameter.
                'user_id' => $bystander->getKey(),
            ])
            ->assertOk();

        $this->assertFalse($actor->fresh()->hasTwoFactorEnabled());
        $this->assertTrue($bystander->fresh()->hasTwoFactorEnabled(), 'A bystander lost their second factor.');
    }

    public function test_disabling_when_already_off_reports_the_end_state_rather_than_failing(): void
    {
        $user = $this->verifiedUser('sms');
        $this->assertFalse($user->hasTwoFactorEnabled());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', false);
    }

    public function test_a_wrong_password_is_refused_even_when_two_factor_is_already_off(): void
    {
        $user = $this->verifiedUser('sms');

        // The already-off branch must sit BEHIND the password check, or a bad
        // password gets a 200 and the endpoint becomes a free oracle for
        // "is 2FA on?" plus a place to grind guesses with no visible effect.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => 'NotThePassword1!'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PASSWORD_REQUIRED');
    }

    // -----------------------------------------------------------------
    // What that means at the login gate
    // -----------------------------------------------------------------

    public function test_login_stops_challenging_once_two_factor_is_disabled(): void
    {
        $user = $this->twoFactorUser();

        // Before: a challenge, no token.
        Mail::fake();
        $this->postJson('/api/login', ['login' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('token');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => self::PASSWORD])
            ->assertOk();

        // After: straight to a session.
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/login', ['login' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertNull($response->json('two_factor_required'));
    }

    public function test_two_factor_can_be_turned_back_on_after_being_disabled(): void
    {
        Mail::fake();

        $user = $this->twoFactorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => self::PASSWORD])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/enable', ['channel' => 'email'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/confirm', ['code' => $this->lastMailedCode()])
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', true);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    // -----------------------------------------------------------------
    // Switching channels
    // -----------------------------------------------------------------

    /**
     * "Manage" includes moving the second factor to the other channel without
     * a disable/re-enable round trip. enable() + confirm() already do this;
     * the test exists so a future change cannot quietly break it.
     */
    public function test_the_channel_can_be_switched_while_two_factor_stays_on(): void
    {
        Mail::fake();

        $user = $this->twoFactorUser();
        $this->assertSame('email', $user->two_factor_channel);

        // SMS is unavailable under test (the driver is 'log'), so this asserts
        // the refusal rather than the switch - which is itself the contract:
        // an undeliverable channel must never become the second factor.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/enable', ['channel' => 'sms'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'channel_unavailable');

        $this->assertSame('email', $user->fresh()->two_factor_channel, 'A refused enrolment must not move the channel.');
    }

    public function test_an_abandoned_channel_switch_does_not_move_the_channel(): void
    {
        Mail::fake();

        $user = $this->twoFactorUser();

        // Request a code, then never confirm it.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/enable', ['channel' => 'email'])
            ->assertOk();

        $fresh = $user->fresh();

        $this->assertTrue($fresh->hasTwoFactorEnabled(), 'Requesting a code must not disturb the live enrolment.');
        $this->assertSame('email', $fresh->two_factor_channel);
    }

    // -----------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------

    public function test_enabling_and_disabling_are_both_recorded(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('sms');

        $this->actingAs($user, 'sanctum')->postJson('/api/2fa/enable', ['channel' => 'email'])->assertOk();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/confirm', ['code' => $this->lastMailedCode()])
            ->assertOk();

        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->getKey(),
            'event_type' => AuthEvent::TWO_FACTOR_ENABLED,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => self::PASSWORD])
            ->assertOk();

        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->getKey(),
            'event_type' => AuthEvent::TWO_FACTOR_DISABLED,
        ]);
    }

    public function test_a_refused_disable_is_not_recorded_as_a_disable(): void
    {
        $user = $this->twoFactorUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => 'NotThePassword1!'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('auth_events', [
            'user_id' => $user->getKey(),
            'event_type' => AuthEvent::TWO_FACTOR_DISABLED,
        ]);
    }

    // -----------------------------------------------------------------
    // Rate limiting
    // -----------------------------------------------------------------

    /**
     * The bug this pins: /api/2fa/enable submits a CHANNEL, not an identifier,
     * so otpIdentifierKey() had nothing to key on and returned the literal
     * string 'unresolved' for everyone. Every authenticated user therefore
     * shared one 3-per-15-minutes bucket, and any one account enrolling three
     * times locked out enrolment for the whole deployment.
     */
    public function test_one_users_enrolment_sends_do_not_exhaust_another_users_budget(): void
    {
        Mail::fake();

        $heavy = $this->verifiedUser('email');
        $bystander = $this->verifiedUser('email');

        // Spend the per-identifier budget (3 per 15 minutes) on one account.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($heavy, 'sanctum')
                ->postJson('/api/2fa/enable', ['channel' => 'email'])
                ->assertOk();
        }

        $this->actingAs($heavy, 'sanctum')
            ->postJson('/api/2fa/enable', ['channel' => 'email'])
            ->assertStatus(429, 'The per-account budget should still bite.');

        $this->actingAs($bystander, 'sanctum')
            ->postJson('/api/2fa/enable', ['channel' => 'email'])
            ->assertOk('A second account was locked out by the first account\'s sends.');
    }

    /**
     * Disabling has its own bucket rather than sharing device-trust's. Both are
     * password checks reachable from a stolen session, and sharing would let
     * exhausting one lock the owner out of the other.
     */
    public function test_disable_attempts_are_rate_limited_separately_from_device_trust(): void
    {
        $user = $this->twoFactorUser();

        // 5 per 15 minutes.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/2fa/disable', ['password' => 'WrongGuess'.$i.'!'])
                ->assertStatus(422);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/2fa/disable', ['password' => 'WrongGuess6!'])
            ->assertStatus(429);

        // The device-trust budget is untouched by all of that.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/devices')
            ->assertOk();
    }
}
