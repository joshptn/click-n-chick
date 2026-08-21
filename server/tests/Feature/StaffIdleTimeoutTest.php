<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceStaffIdleTimeout;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Staff sessions expire after 8 hours of inactivity, and still never outlive
 * the 12-hour absolute cap.
 *
 * Implemented by sliding the token's own expires_at, so the enforcement is
 * Sanctum's ordinary expiry check rather than a second parallel one. That is
 * why every assertion here replays a real bearer token instead of inspecting
 * the row: what matters is whether the credential still authenticates.
 *
 * Note the window opens at the first authenticated request, not at the moment
 * the token is minted - the middleware has to run to slide anything. In
 * practice the SPA calls /api/user immediately after sign-in, so the gap is
 * milliseconds.
 */
class StaffIdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private const DEVICE = 'the-back-office-terminal';

    private const AGENT = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0';

    private int $phoneSeq = 0;

    private function user(string $role): User
    {
        $phone = '+63917555'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => 'email',
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ]);

        $user->role = $role;
        $user->save();

        return $user->fresh();
    }

    private function signIn(User $user): string
    {
        Mail::fake();

        $token = $this->withHeaders([
            DeviceRegistrar::HINT_HEADER => self::DEVICE,
            'User-Agent' => self::AGENT,
        ])->postJson('/api/login', [
            'login' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    /** Replay the session from the device it was issued to. */
    private function replay(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            DeviceRegistrar::HINT_HEADER => self::DEVICE,
            'User-Agent' => self::AGENT,
        ])->getJson('/api/user');
    }

    // -----------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------

    public function test_a_staff_session_dies_after_eight_hours_of_inactivity(): void
    {
        $token = $this->signIn($this->user(User::ROLE_SUPER_ADMIN));

        // Opens the idle window.
        $this->replay($token)->assertOk();

        $this->travel(EnforceStaffIdleTimeout::IDLE_MINUTES + 5)->minutes();

        $this->replay($token)->assertUnauthorized();
    }

    public function test_a_staff_session_survives_activity_spread_across_the_idle_window(): void
    {
        $token = $this->signIn($this->user(User::ROLE_ADMIN));

        $this->replay($token)->assertOk();

        // Well past 8 hours in total, but never 8 hours idle.
        $this->travel(300)->minutes();
        $this->replay($token)->assertOk();

        $this->travel(300)->minutes();
        $this->replay($token)->assertOk();
    }

    /**
     * The cap the sliding must never breach. Activity extends a session up to
     * 12 hours from issue and no further - otherwise "12-hour staff session"
     * would quietly become "forever, as long as you keep clicking".
     */
    public function test_activity_cannot_push_a_staff_session_past_the_twelve_hour_cap(): void
    {
        $token = $this->signIn($this->user(User::ROLE_SUPER_ADMIN));

        $this->replay($token)->assertOk();

        $this->travel(420)->minutes(); // 7h - inside the idle window
        $this->replay($token)->assertOk();

        // 13h after issue: still active, but the absolute cap has passed.
        $this->travel(360)->minutes();
        $this->replay($token)->assertUnauthorized();
    }

    public function test_the_absolute_cap_is_still_the_configured_staff_lifetime(): void
    {
        $user = $this->user(User::ROLE_SUPER_ADMIN);
        $token = $this->signIn($user);

        $this->replay($token)->assertOk();

        $expiry = $user->tokens()->first()->expires_at;

        // Slid down to the idle window, never above the staff lifetime.
        $this->assertTrue($expiry->lessThanOrEqualTo(now()->addMinutes(EnforceStaffIdleTimeout::IDLE_MINUTES)));
        $this->assertTrue($expiry->lessThanOrEqualTo(now()->addMinutes(DeviceRegistrar::STAFF_SESSION_MINUTES)));
    }

    // -----------------------------------------------------------------
    // Customers are deliberately exempt
    // -----------------------------------------------------------------

    public function test_a_customer_session_is_not_subject_to_the_idle_timeout(): void
    {
        $token = $this->signIn($this->user(User::ROLE_CUSTOMER));

        $this->replay($token)->assertOk();

        // Far past the staff idle window, and well past the staff cap.
        $this->travel(EnforceStaffIdleTimeout::IDLE_MINUTES + 600)->minutes();

        $this->replay($token)->assertOk();
    }

    public function test_a_customer_session_keeps_its_full_lifetime(): void
    {
        $user = $this->user(User::ROLE_CUSTOMER);
        $token = $this->signIn($user);

        $this->replay($token)->assertOk();

        $expiry = $user->tokens()->first()->expires_at;

        // Untouched by the middleware: still the full customer window.
        $this->assertTrue(
            $expiry->greaterThan(now()->addMinutes(DeviceRegistrar::CUSTOMER_SESSION_MINUTES - 60)),
            'A customer session must keep its full lifetime.'
        );
    }

    /**
     * A promoted account picks up the shorter rules on its existing session.
     *
     * The check reads the CURRENT role rather than anything stamped on the
     * token, so a customer made Store Agent stops enjoying the 14-day window
     * immediately rather than after their next sign-in.
     */
    public function test_a_promoted_user_becomes_subject_to_the_idle_timeout(): void
    {
        $user = $this->user(User::ROLE_CUSTOMER);
        $token = $this->signIn($user);

        $this->replay($token)->assertOk();

        $user->role = User::ROLE_ADMIN;
        $user->save();

        // One request as staff to slide the expiry down to the idle window.
        $this->replay($token)->assertOk();

        $this->travel(EnforceStaffIdleTimeout::IDLE_MINUTES + 5)->minutes();

        $this->replay($token)->assertUnauthorized();
    }
}
