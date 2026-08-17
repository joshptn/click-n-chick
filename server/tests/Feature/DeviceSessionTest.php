<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\SessionRevoked;
use App\Mail\NewDeviceAlertMail;
use App\Models\AuthEvent;
use App\Models\KnownDevice;
use App\Models\Notification;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Known devices and remote logout (FR-01.13 / UC-AUTH-013).
 *
 * The behaviour that matters is not "the row was deleted" but "the credential
 * that device holds stops working, and no other device notices". Every
 * revocation test therefore ends by replaying a real bearer token against a
 * protected route rather than asserting on the database.
 */
class DeviceSessionTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    private function verifiedUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('Password123!'),
        ]);

        // Unique per user: phone_number_hash is a unique index.
        $phone = '+63917123'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        $user->phone_number = $phone;
        $user->phone_number_hash = User::hashPhoneNumber($phone);
        $user->verification_channel = 'sms';
        $user->phone_verified_at = now();
        $user->account_status = User::STATUS_ACTIVE;
        $user->save();

        return $user->fresh();
    }

    /** Sign in as if from a distinct physical device. Returns the bearer token. */
    private function signInFrom(User $user, string $deviceId, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0'): string
    {
        $response = $this->withHeaders([
            DeviceRegistrar::HINT_HEADER => $deviceId,
            'User-Agent' => $agent,
        ])->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertOk();

        return $response->json('token');
    }

    /**
     * Make the next request as the holder of this bearer token.
     *
     * forgetGuards() matters: one application instance serves every request in
     * a test method, and the sanctum guard caches the user it resolved. Without
     * this, a token that authenticated earlier in the same test keeps working
     * after being revoked - an artefact of the harness that would quietly make
     * every revocation assertion below meaningless.
     */
    private function actingAsDevice(string $token, array $extra = []): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token] + $extra);
    }

    /**
     * Mark the caller's OWN device trusted.
     *
     * The bootstrap every remote action needs: a fresh account has no trusted
     * device, and acting on any device other than the one you hold requires
     * one. Trusting yourself is always permitted, which is what makes the
     * first trusted device obtainable at all.
     */
    private function trustSelf(string $token): int
    {
        $currentId = $this->actingAsDevice($token)
            ->getJson('/api/user/devices')
            ->assertOk()
            ->json('current_device_id');

        $this->actingAsDevice($token)
            ->patchJson("/api/user/devices/{$currentId}/trust", [
                'trusted' => true,
                // Granting trust re-checks the account password.
                'password' => 'Password123!',
            ])
            ->assertOk();

        return $currentId;
    }

    // -----------------------------------------------------------------
    // Devices are created by signing in, and bound to the session
    // -----------------------------------------------------------------

    public function test_signing_in_records_the_device_and_binds_it_to_the_issued_token(): void
    {
        $user = $this->verifiedUser('one@example.test');

        $token = $this->signInFrom($user, 'laptop', 'Mozilla/5.0 (Macintosh; Mac OS X) Firefox/121.0');

        $device = KnownDevice::where('user_id', $user->id)->sole();

        $this->assertSame('Firefox on macOS', $device->device_name);
        $this->assertSame('macOS', $device->platform);
        $this->assertNotNull($device->last_seen_at);

        // The token issued by that login points at that device - this is the
        // join the whole feature rests on.
        $accessToken = PersonalAccessToken::findToken($token);
        $this->assertSame($device->id, $accessToken->known_device_id);
    }

    public function test_signing_in_again_from_the_same_device_reuses_the_row(): void
    {
        $user = $this->verifiedUser('two@example.test');

        $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'laptop');

        // One device, two live sessions - not two devices.
        $this->assertSame(1, KnownDevice::where('user_id', $user->id)->count());
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_distinct_devices_produce_distinct_rows(): void
    {
        $user = $this->verifiedUser('three@example.test');

        $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1');

        $this->assertSame(2, KnownDevice::where('user_id', $user->id)->count());
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    public function test_a_user_can_list_their_own_devices_and_see_which_is_current(): void
    {
        $user = $this->verifiedUser('four@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $response = $this->actingAsDevice($laptop)
            ->getJson('/api/user/devices')
            ->assertOk();

        $devices = $response->json('devices');
        $this->assertCount(2, $devices);

        $current = collect($devices)->firstWhere('is_current', true);
        $this->assertNotNull($current, 'the requesting device should be flagged');
        $this->assertSame('Chrome on Windows', $current['name']);
        $this->assertSame(1, $current['active_session_count']);
        $this->assertTrue($current['is_active']);

        // Exactly one device is "this one".
        $this->assertSame(1, collect($devices)->where('is_current', true)->count());
    }

    public function test_the_listing_never_exposes_a_token_or_a_fingerprint(): void
    {
        $user = $this->verifiedUser('five@example.test');
        $token = $this->signInFrom($user, 'laptop');

        $body = $this->actingAsDevice($token)
            ->getJson('/api/user/devices')
            ->assertOk()
            ->getContent();

        // The plaintext token, the stored hash, and the device fingerprint must
        // none of them appear anywhere in the payload.
        $accessToken = PersonalAccessToken::findToken($token);
        $fingerprint = KnownDevice::where('user_id', $user->id)->sole()->device_fingerprint;

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString($accessToken->token, $body);
        $this->assertStringNotContainsString($fingerprint, $body);
        $this->assertStringNotContainsString('device_fingerprint', $body);
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/user/devices')->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Ownership
    // -----------------------------------------------------------------

    public function test_a_user_cannot_see_another_accounts_devices(): void
    {
        $alice = $this->verifiedUser('alice@example.test');
        $bob = $this->verifiedUser('bob@example.test');

        $aliceToken = $this->signInFrom($alice, 'alice-laptop');
        $this->signInFrom($bob, 'bob-laptop');

        $devices = $this->actingAsDevice($aliceToken)
            ->getJson('/api/user/devices')
            ->assertOk()
            ->json('devices');

        $this->assertCount(1, $devices);

        $bobDeviceId = KnownDevice::where('user_id', $bob->id)->sole()->id;
        $this->assertNotContains($bobDeviceId, collect($devices)->pluck('id')->all());
    }

    public function test_a_user_cannot_revoke_another_accounts_device(): void
    {
        $alice = $this->verifiedUser('alice2@example.test');
        $bob = $this->verifiedUser('bob2@example.test');

        $aliceToken = $this->signInFrom($alice, 'alice-laptop');
        $bobToken = $this->signInFrom($bob, 'bob-laptop');

        $bobDevice = KnownDevice::where('user_id', $bob->id)->sole();

        // 404, not 403: confirming the id exists would leak that some other
        // account owns a device with that id.
        $this->actingAsDevice($aliceToken)
            ->deleteJson('/api/user/devices/'.$bobDevice->id)
            ->assertNotFound();

        // Bob is still signed in - the attempt changed nothing.
        $this->assertSame(1, $bobDevice->tokens()->count());
        $this->actingAsDevice($bobToken)->getJson('/api/user')->assertOk();
    }

    public function test_revoking_requires_authentication(): void
    {
        $user = $this->verifiedUser('six@example.test');
        $this->signInFrom($user, 'laptop');

        $device = KnownDevice::where('user_id', $user->id)->sole();

        $this->deleteJson('/api/user/devices/'.$device->id)->assertUnauthorized();
        $this->assertSame(1, $device->tokens()->count());
    }

    // -----------------------------------------------------------------
    // Revocation - the actual security operation
    // -----------------------------------------------------------------

    public function test_revoking_a_device_stops_its_previously_issued_token_from_authenticating(): void
    {
        $user = $this->verifiedUser('seven@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1');

        // The phone's credential works right up until the revocation.
        $this->actingAsDevice($phone)->getJson('/api/user')->assertOk();

        // Signing out a device other than your own is a trusted-device action.
        $this->trustSelf($laptop);

        $phoneDevice = KnownDevice::where('user_id', $user->id)
            ->where('platform', 'iOS')
            ->sole();

        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'revoked_sessions' => 1,
                'current_device_revoked' => false,
            ]);

        // The credential the phone still physically holds no longer authenticates.
        $this->actingAsDevice($phone)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_revoking_one_device_leaves_every_other_device_signed_in(): void
    {
        $user = $this->verifiedUser('eight@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $tablet = $this->signInFrom($user, 'tablet', 'Mozilla/5.0 (iPad; CPU OS 17_0) Safari/605.1');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->trustSelf($laptop);

        $phoneDevice = KnownDevice::where('user_id', $user->id)
            ->where('platform', 'Android')
            ->sole();

        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)
            ->assertOk();

        // Only the selected device lost access.
        $this->actingAsDevice($phone)->getJson('/api/user')->assertUnauthorized();
        $this->actingAsDevice($laptop)->getJson('/api/user')->assertOk();
        $this->actingAsDevice($tablet)->getJson('/api/user')->assertOk();

        $this->assertSame(2, $user->fresh()->tokens()->count());
    }

    public function test_revoking_a_device_kills_all_of_its_sessions_not_just_the_newest(): void
    {
        $user = $this->verifiedUser('nine@example.test');

        // Two logins from one device - e.g. signed in, then signed in again
        // without the first token ever being revoked.
        $first = $this->signInFrom($user, 'shared-laptop');
        $second = $this->signInFrom($user, 'shared-laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->trustSelf($phone);

        $laptopDevice = KnownDevice::where('user_id', $user->id)
            ->where('platform', 'Windows')
            ->sole();

        $this->actingAsDevice($phone)
            ->deleteJson('/api/user/devices/'.$laptopDevice->id)
            ->assertOk()
            ->assertJson(['revoked_sessions' => 2]);

        $this->actingAsDevice($first)->getJson('/api/user')->assertUnauthorized();
        $this->actingAsDevice($second)->getJson('/api/user')->assertUnauthorized();
        $this->actingAsDevice($phone)->getJson('/api/user')->assertOk();
    }

    public function test_the_device_row_survives_revocation_and_is_reported_as_signed_out(): void
    {
        $user = $this->verifiedUser('ten@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->trustSelf($laptop);

        $phoneDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Android')->sole();

        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)
            ->assertOk();

        $devices = $this->actingAsDevice($laptop)
            ->getJson('/api/user/devices')
            ->assertOk()
            ->json('devices');

        // Still listed - the account remembers the device - but with no session.
        $phoneEntry = collect($devices)->firstWhere('id', $phoneDevice->id);
        $this->assertNotNull($phoneEntry);
        $this->assertFalse($phoneEntry['is_active']);
        $this->assertSame(0, $phoneEntry['active_session_count']);
    }

    // -----------------------------------------------------------------
    // Revoking the device you are holding
    // -----------------------------------------------------------------

    public function test_revoking_the_current_device_signs_this_session_out_and_says_so(): void
    {
        $user = $this->verifiedUser('eleven@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $laptopDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Windows')->sole();

        // The laptop revokes itself.
        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$laptopDevice->id)
            ->assertOk()
            ->assertJson(['current_device_revoked' => true]);

        // Its own credential is dead...
        $this->actingAsDevice($laptop)->getJson('/api/user')->assertUnauthorized();
        // ...and the other device is untouched.
        $this->actingAsDevice($phone)->getJson('/api/user')->assertOk();
    }

    public function test_revoking_a_device_that_is_already_signed_out_is_harmless(): void
    {
        $user = $this->verifiedUser('twelve@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->trustSelf($laptop);

        $phoneDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Android')->sole();

        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)->assertOk();

        // Second call: nothing left to revoke, still a success, laptop unharmed.
        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)
            ->assertOk()
            ->assertJson(['revoked_sessions' => 0]);

        $this->actingAsDevice($laptop)->getJson('/api/user')->assertOk();
    }

    public function test_a_missing_device_id_is_a_404_rather_than_an_error(): void
    {
        $user = $this->verifiedUser('thirteen@example.test');
        $token = $this->signInFrom($user, 'laptop');

        $this->actingAsDevice($token)
            ->deleteJson('/api/user/devices/999999')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Trust (FR-01.13): only a trusted device may act on another device
    // -----------------------------------------------------------------

    public function test_an_untrusted_device_cannot_sign_out_another_device(): void
    {
        $user = $this->verifiedUser('trust1@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $phoneDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Android')->sole();

        // No device has been trusted yet.
        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)
            ->assertForbidden()
            ->assertJson(['code' => 'DEVICE_NOT_TRUSTED']);

        // The refusal is real: the phone is still signed in.
        $this->actingAsDevice($phone)->getJson('/api/user')->assertOk();
        $this->assertSame(1, $phoneDevice->tokens()->count());
    }

    public function test_a_device_may_always_trust_itself_which_is_how_the_first_trust_is_obtained(): void
    {
        $user = $this->verifiedUser('trust2@example.test');
        $laptop = $this->signInFrom($user, 'laptop');

        $currentId = $this->actingAsDevice($laptop)
            ->getJson('/api/user/devices')
            ->assertOk()
            ->assertJson(['current_device_trusted' => false])
            ->json('current_device_id');

        $this->actingAsDevice($laptop)
            ->patchJson("/api/user/devices/{$currentId}/trust", [
                'trusted' => true,
                'password' => 'Password123!',
            ])
            ->assertOk()
            ->assertJson(['device' => ['is_trusted' => true]]);

        $this->actingAsDevice($laptop)
            ->getJson('/api/user/devices')
            ->assertOk()
            ->assertJson(['current_device_trusted' => true]);
    }

    public function test_an_untrusted_device_cannot_trust_a_different_device(): void
    {
        $user = $this->verifiedUser('trust3@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $phoneDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Android')->sole();

        // Otherwise an untrusted device could simply promote itself by proxy.
        $this->actingAsDevice($laptop)
            ->patchJson('/api/user/devices/'.$phoneDevice->id.'/trust', [
                'trusted' => true,
                'password' => 'Password123!',
            ])
            ->assertForbidden()
            ->assertJson(['code' => 'DEVICE_NOT_TRUSTED']);

        $this->assertFalse($phoneDevice->fresh()->is_trusted);
    }

    public function test_a_trusted_target_can_still_be_signed_out(): void
    {
        $user = $this->verifiedUser('trust4@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        // Both devices trusted. Trust must not make a device unremovable -
        // otherwise marking a stolen device trusted would lock it in forever.
        $this->trustSelf($laptop);
        $this->trustSelf($phone);

        $phoneDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Android')->sole();
        $this->assertTrue($phoneDevice->is_trusted);

        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)
            ->assertOk()
            ->assertJson(['revoked_sessions' => 1]);

        $this->actingAsDevice($phone)->getJson('/api/user')->assertUnauthorized();
        // Still trusted, just signed out - trust is about the device, not the session.
        $this->assertTrue($phoneDevice->fresh()->is_trusted);
    }

    public function test_signing_yourself_out_never_requires_trust(): void
    {
        $user = $this->verifiedUser('trust5@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $laptopDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Windows')->sole();
        $this->assertFalse($laptopDevice->is_trusted);

        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$laptopDevice->id)
            ->assertOk()
            ->assertJson(['current_device_revoked' => true]);
    }

    public function test_a_user_cannot_trust_another_accounts_device(): void
    {
        $alice = $this->verifiedUser('trust6@example.test');
        $bob = $this->verifiedUser('trust7@example.test');

        $aliceToken = $this->signInFrom($alice, 'alice-laptop');
        $bobToken = $this->signInFrom($bob, 'bob-laptop');

        // Even from a trusted device: ownership is checked before trust, so
        // this 404s rather than leaking that the id exists elsewhere.
        $this->trustSelf($aliceToken);

        $bobDevice = KnownDevice::where('user_id', $bob->id)->sole();

        $this->actingAsDevice($aliceToken)
            ->patchJson('/api/user/devices/'.$bobDevice->id.'/trust', [
                'trusted' => true,
                'password' => 'Password123!',
            ])
            ->assertNotFound();

        $this->assertFalse($bobDevice->fresh()->is_trusted);
        $this->actingAsDevice($bobToken)->getJson('/api/user')->assertOk();
    }

    // -----------------------------------------------------------------
    // Granting trust is password-gated
    // -----------------------------------------------------------------

    public function test_trusting_a_device_without_the_password_is_refused(): void
    {
        $user = $this->verifiedUser('pw1@example.test');
        $laptop = $this->signInFrom($user, 'laptop');

        $currentId = $this->actingAsDevice($laptop)
            ->getJson('/api/user/devices')->json('current_device_id');

        // A stolen session must not be able to promote itself in one click.
        $this->actingAsDevice($laptop)
            ->patchJson("/api/user/devices/{$currentId}/trust", ['trusted' => true])
            ->assertStatus(422)
            ->assertJson(['code' => 'PASSWORD_REQUIRED']);

        $this->assertFalse(KnownDevice::find($currentId)->is_trusted);
    }

    public function test_trusting_a_device_with_the_wrong_password_is_refused(): void
    {
        $user = $this->verifiedUser('pw2@example.test');
        $laptop = $this->signInFrom($user, 'laptop');

        $currentId = $this->actingAsDevice($laptop)
            ->getJson('/api/user/devices')->json('current_device_id');

        $this->actingAsDevice($laptop)
            ->patchJson("/api/user/devices/{$currentId}/trust", [
                'trusted' => true,
                'password' => 'NotThePassword1!',
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'PASSWORD_REQUIRED']);

        $this->assertFalse(KnownDevice::find($currentId)->is_trusted);
    }

    public function test_removing_trust_does_not_require_a_password(): void
    {
        $user = $this->verifiedUser('pw3@example.test');
        $laptop = $this->signInFrom($user, 'laptop');

        $currentId = $this->trustSelf($laptop);

        // Only ever reduces privilege, so putting a password in the way would
        // make the safer action the harder one.
        $this->actingAsDevice($laptop)
            ->patchJson("/api/user/devices/{$currentId}/trust", ['trusted' => false])
            ->assertOk()
            ->assertJson(['device' => ['is_trusted' => false]]);
    }

    // -----------------------------------------------------------------
    // Sign out all other devices
    // -----------------------------------------------------------------

    public function test_signing_out_other_devices_clears_every_session_but_this_one(): void
    {
        $user = $this->verifiedUser('sweep1@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');
        $tablet = $this->signInFrom($user, 'tablet', 'Mozilla/5.0 (iPad; CPU OS 17_0) Safari/605.1');

        $this->trustSelf($laptop);

        $this->actingAsDevice($laptop)
            ->postJson('/api/user/devices/sign-out-others')
            ->assertOk()
            ->assertJson(['success' => true, 'devices_signed_out' => 2]);

        $this->actingAsDevice($phone)->getJson('/api/user')->assertUnauthorized();
        $this->actingAsDevice($tablet)->getJson('/api/user')->assertUnauthorized();
        // The device that pulled the lever keeps working.
        $this->actingAsDevice($laptop)->getJson('/api/user')->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_signing_out_other_devices_does_not_spare_trusted_ones(): void
    {
        $user = $this->verifiedUser('sweep2@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->trustSelf($laptop);
        $this->trustSelf($phone);

        // A panic button that leaves some sessions alive is a bad panic button:
        // the moment you need it is the moment you cannot be sure which
        // devices you still control.
        $this->actingAsDevice($laptop)
            ->postJson('/api/user/devices/sign-out-others')
            ->assertOk();

        $this->actingAsDevice($phone)->getJson('/api/user')->assertUnauthorized();
        $this->actingAsDevice($laptop)->getJson('/api/user')->assertOk();
    }

    public function test_signing_out_other_devices_also_kills_sessions_with_no_device_link(): void
    {
        $user = $this->verifiedUser('sweep3@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $this->trustSelf($laptop);

        // A token predating device tracking. In SQL `known_device_id != X` is
        // NULL rather than true for these, so a naive query would leave behind
        // exactly the session nothing on the devices screen can show.
        $orphan = $user->createToken('legacy')->plainTextToken;
        $this->actingAsDevice($orphan)->getJson('/api/user')->assertOk();

        $this->actingAsDevice($laptop)
            ->postJson('/api/user/devices/sign-out-others')
            ->assertOk();

        $this->actingAsDevice($orphan)->getJson('/api/user')->assertUnauthorized();
        $this->actingAsDevice($laptop)->getJson('/api/user')->assertOk();
    }

    public function test_an_untrusted_device_cannot_sign_out_other_devices(): void
    {
        $user = $this->verifiedUser('sweep4@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->actingAsDevice($laptop)
            ->postJson('/api/user/devices/sign-out-others')
            ->assertForbidden()
            ->assertJson(['code' => 'DEVICE_NOT_TRUSTED']);

        $this->actingAsDevice($phone)->getJson('/api/user')->assertOk();
    }

    public function test_signing_out_other_devices_never_touches_another_account(): void
    {
        $alice = $this->verifiedUser('sweep5@example.test');
        $bob = $this->verifiedUser('sweep6@example.test');

        $aliceLaptop = $this->signInFrom($alice, 'alice-laptop');
        $bobToken = $this->signInFrom($bob, 'bob-laptop');

        $this->trustSelf($aliceLaptop);

        $this->actingAsDevice($aliceLaptop)
            ->postJson('/api/user/devices/sign-out-others')
            ->assertOk();

        $this->actingAsDevice($bobToken)->getJson('/api/user')->assertOk();
    }

    // -----------------------------------------------------------------
    // FR-01.11: unrecognised-device login is recorded and alerted
    // -----------------------------------------------------------------

    public function test_a_first_ever_device_is_recorded_but_raises_no_alert(): void
    {
        Mail::fake();

        $user = $this->verifiedUser('alert1@example.test');
        $this->signInFrom($user, 'laptop');

        // Recorded...
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->id,
            'event_type' => DeviceRegistrar::EVENT_NEW_DEVICE,
        ]);

        // ...but not alerted: there is no earlier device to compare against and
        // the user is the one signing in.
        Mail::assertNothingSent();
        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }

    public function test_a_second_unrecognised_device_records_an_event_and_alerts_the_account_holder(): void
    {
        Mail::fake();
        Event::fake([NotificationBroadcast::class]);

        $user = $this->verifiedUser('alert2@example.test');

        $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        // One event per unrecognised device, not per login.
        $this->assertSame(2, AuthEvent::where('user_id', $user->id)
            ->where('event_type', DeviceRegistrar::EVENT_NEW_DEVICE)
            ->count());

        // In-app notice naming the device.
        $notification = Notification::where('user_id', $user->id)->sole();
        $this->assertStringContainsString('New sign-in', $notification->title);
        $this->assertStringContainsString('Chrome on Android', $notification->body);

        // Pushed live, and emailed - the in-app notice is useless in the case
        // that matters, where the attacker is the one holding the app.
        Event::assertDispatched(NotificationBroadcast::class);
        Mail::assertSent(NewDeviceAlertMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_returning_to_a_known_device_raises_nothing(): void
    {
        $user = $this->verifiedUser('alert3@example.test');

        $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        Mail::fake();

        // Same two devices again: recognised, so silent.
        $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        Mail::assertNothingSent();
        $this->assertSame(2, AuthEvent::where('user_id', $user->id)
            ->where('event_type', DeviceRegistrar::EVENT_NEW_DEVICE)
            ->count());
    }

    public function test_a_failing_alert_never_breaks_the_login(): void
    {
        $user = $this->verifiedUser('alert4@example.test');
        $this->signInFrom($user, 'laptop');

        // A mail transport that throws must not cost the user their sign-in:
        // the password was correct and the session already exists.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $token = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->assertNotEmpty($token);
        $this->actingAsDevice($token)->getJson('/api/user')->assertOk();
    }

    // -----------------------------------------------------------------
    // Revoked devices are told to leave
    // -----------------------------------------------------------------

    public function test_revoking_another_device_broadcasts_so_that_browser_can_leave(): void
    {
        Event::fake([SessionRevoked::class]);

        $user = $this->verifiedUser('revoke-event@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->trustSelf($laptop);

        $phoneDevice = KnownDevice::where('user_id', $user->id)->where('platform', 'Android')->sole();

        $this->actingAsDevice($laptop)
            ->deleteJson('/api/user/devices/'.$phoneDevice->id)
            ->assertOk();

        Event::assertDispatched(
            SessionRevoked::class,
            fn (SessionRevoked $event) => $event->deviceId === $phoneDevice->id
                && $event->userId === $user->id
        );
    }

    // -----------------------------------------------------------------
    // Ordinary sign-out ends ONE session, not the whole account
    // -----------------------------------------------------------------

    public function test_logging_out_leaves_the_users_other_devices_signed_in(): void
    {
        $user = $this->verifiedUser('logout@example.test');

        $laptop = $this->signInFrom($user, 'laptop');
        $phone = $this->signInFrom($user, 'phone', 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0');

        $this->actingAsDevice($laptop)->postJson('/api/logout')->assertOk();

        $this->actingAsDevice($laptop)->getJson('/api/user')->assertUnauthorized();
        // The whole point: signing out here is not signing out everywhere.
        $this->actingAsDevice($phone)->getJson('/api/user')->assertOk();
    }

    // -----------------------------------------------------------------
    // A client-supplied device id is not proof of anything
    // -----------------------------------------------------------------

    public function test_claiming_another_users_device_hint_does_not_grant_access_to_their_device(): void
    {
        $alice = $this->verifiedUser('alice3@example.test');
        $bob = $this->verifiedUser('bob3@example.test');

        $this->signInFrom($alice, 'shared-hint');
        $bobToken = $this->signInFrom($bob, 'shared-hint');

        $aliceDevice = KnownDevice::where('user_id', $alice->id)->sole();
        $bobDevice = KnownDevice::where('user_id', $bob->id)->sole();

        // Same hint, same fingerprint - but they are separate rows, because the
        // row is keyed by (user_id, fingerprint).
        $this->assertNotSame($aliceDevice->id, $bobDevice->id);
        $this->assertSame($aliceDevice->device_fingerprint, $bobDevice->device_fingerprint);

        // Bob sending the matching hint still cannot reach Alice's device.
        $this->actingAsDevice($bobToken, [DeviceRegistrar::HINT_HEADER => 'shared-hint'])
            ->deleteJson('/api/user/devices/'.$aliceDevice->id)
            ->assertNotFound();

        $this->assertSame(1, $aliceDevice->tokens()->count());
    }
}
