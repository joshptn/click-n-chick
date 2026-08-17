<?php

namespace Tests\Feature;

use App\Models\KnownDevice;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
