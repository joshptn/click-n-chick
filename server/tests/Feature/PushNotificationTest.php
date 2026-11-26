<?php

namespace Tests\Feature;

use App\Jobs\NotifyEveryAccount;
use App\Jobs\SendNewDeviceAlert;
use App\Models\KnownDevice;
use App\Models\PushToken;
use App\Models\User;
use App\Utils\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Push through Firebase Cloud Messaging (PRD §9, Mobile-Plan phase 2).
 *
 * Firebase is faked at the HTTP layer, so these run the real JWT signing and
 * the real FCM request shape against a throwaway service account key.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/v1/projects/cnc-test/messages:send';

    /** @var list<array{token: string, title: string, body: string, data: array}> */
    private array $sent = [];

    /** token => FCM error response for that token */
    private array $failures = [];

    private function configureFirebase(): void
    {
        config([
            'services.firebase.credentials' => json_encode([
                'type' => 'service_account',
                'project_id' => 'cnc-test',
                'private_key_id' => 'test-key',
                'private_key' => $this->throwawayKey(),
                'client_email' => 'push@cnc-test.iam.gserviceaccount.com',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]),
            'services.firebase.project_id' => null,
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => function (HttpRequest $request) {
                $message = $request->data()['message'];

                if (isset($this->failures[$message['token']])) {
                    return $this->failures[$message['token']];
                }

                $this->sent[] = [
                    'token' => $message['token'],
                    'title' => $message['notification']['title'],
                    'body' => $message['notification']['body'],
                    'data' => $message['data'] ?? [],
                ];

                return Http::response(['name' => 'projects/cnc-test/messages/1']);
            },
        ]);
    }

    /** A fresh RSA key; Windows PHP needs a config file to make one. */
    private function throwawayKey(): string
    {
        $config = tempnam(sys_get_temp_dir(), 'ossl');
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        openssl_pkey_export($key, $pem, null, ['config' => $config]);
        unlink($config);

        return $pem;
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'account_status' => User::STATUS_ACTIVE]);
    }

    /** A device registered the way the app does it, on a real sign-in. Returns the bearer. */
    private function registerDevice(User $user, string $fcmToken, string $platform = PushToken::PLATFORM_WEB): string
    {
        $bearer = $user->createToken('app')->plainTextToken;

        $this->withToken($bearer)
            ->postJson('/api/push-tokens', ['token' => $fcmToken, 'platform' => $platform])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        return $bearer;
    }

    public function test_a_customer_registers_a_token_tied_to_the_current_sign_in(): void
    {
        $user = $this->customer();
        $bearer = $this->registerDevice($user, 'fcm-a', PushToken::PLATFORM_ANDROID);

        $session = PersonalAccessToken::findToken($bearer);
        $row = PushToken::query()->where('token', 'fcm-a')->sole();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($session->id, $row->personal_access_token_id);
        $this->assertSame('android', $row->platform);
    }

    public function test_registering_again_moves_the_token_to_the_account_now_signed_in(): void
    {
        $first = $this->customer();
        $second = $this->customer();

        $this->registerDevice($first, 'shared-phone');
        $this->registerDevice($second, 'shared-phone');

        $this->assertSame(1, PushToken::count());
        $this->assertSame($second->id, PushToken::sole()->user_id);
    }

    public function test_staff_can_register_a_token_too(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $staff = User::factory()->create(['role' => $role, 'account_status' => User::STATUS_ACTIVE]);

            $this->registerDevice($staff, 'staff-'.$role);
        }

        $this->assertEqualsCanonicalizing(['staff-admin', 'staff-super_admin'], PushToken::pluck('token')->all());
    }

    public function test_a_staff_notification_is_pushed_with_the_role_so_it_opens_the_portal(): void
    {
        $this->configureFirebase();
        $staff = User::factory()->create(['role' => User::ROLE_ADMIN, 'account_status' => User::STATUS_ACTIVE]);
        $this->registerDevice($staff, 'counter-laptop');

        $notification = Notification::push($staff->id, 'New Senior/PWD application', 'Juan sent an ID to review.', 'discount_review');

        $this->assertSame(['counter-laptop'], array_column($this->sent, 'token'));
        $this->assertSame([
            'notification_id' => (string) $notification->id,
            'type' => 'discount_review',
            'role' => 'admin',
        ], $this->sent[0]['data']);
    }

    public function test_registration_is_validated(): void
    {
        $bearer = $this->customer()->createToken('app')->plainTextToken;

        $this->withToken($bearer)
            ->postJson('/api/push-tokens', ['token' => 'x', 'platform' => 'ios'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform');

        $this->withToken($bearer)
            ->postJson('/api/push-tokens', ['platform' => 'web'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_signing_out_deletes_that_devices_token_only(): void
    {
        $user = $this->customer();
        $phone = $this->registerDevice($user, 'phone');
        $this->registerDevice($user, 'laptop');

        $this->withToken($phone)->postJson('/api/logout')->assertOk();

        $this->assertSame(['laptop'], PushToken::pluck('token')->all());
    }

    public function test_signing_a_device_out_remotely_deletes_its_token(): void
    {
        $user = $this->customer();
        $device = KnownDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint' => 'fp',
            'device_name' => 'Chrome on Android',
        ]);

        $bearer = $this->registerDevice($user, 'revoked-phone');
        PersonalAccessToken::findToken($bearer)->forceFill(['known_device_id' => $device->id])->save();

        $device->delete();

        $this->assertSame(0, PushToken::count());
    }

    public function test_every_session_ending_deletes_the_tokens(): void
    {
        $user = $this->customer();
        $this->registerDevice($user, 'one');
        $this->registerDevice($user, 'two');

        // What a password change, a reset and an expired-session prune all do.
        $user->tokens()->delete();

        $this->assertSame(0, PushToken::count());
    }

    public function test_a_customer_can_turn_notifications_off_for_their_own_token_only(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $bearer = $this->registerDevice($owner, 'mine');
        $this->registerDevice($other, 'theirs');

        $this->withToken($bearer)->deleteJson('/api/push-tokens', ['token' => 'theirs'])->assertOk();
        $this->assertSame(2, PushToken::count());

        $this->withToken($bearer)->deleteJson('/api/push-tokens', ['token' => 'mine'])->assertOk();
        $this->assertSame(['theirs'], PushToken::pluck('token')->all());
    }

    public function test_a_customer_notification_is_also_pushed_to_their_devices(): void
    {
        $this->configureFirebase();
        $user = $this->customer();
        $this->registerDevice($user, 'phone', PushToken::PLATFORM_ANDROID);
        $this->registerDevice($user, 'laptop');
        $this->registerDevice($this->customer(), 'someone-else');

        $notification = Notification::push($user->id, 'Order Preparing', 'Your order #7 is being prepared now.', 'order');

        $this->assertEqualsCanonicalizing(['phone', 'laptop'], array_column($this->sent, 'token'));
        $this->assertSame('Order Preparing', $this->sent[0]['title']);
        $this->assertSame('Your order #7 is being prepared now.', $this->sent[0]['body']);
        $this->assertSame(['notification_id' => (string) $notification->id, 'type' => 'order'], $this->sent[0]['data']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::FCM
            && $request->hasHeader('Authorization', 'Bearer test-access')
            && $request->data()['message']['android']['priority'] === 'high');

        Http::assertSent(function (HttpRequest $request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
                return false;
            }

            [$header, $claims] = array_map(
                fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true),
                array_slice(explode('.', $request->data()['assertion']), 0, 2)
            );

            return $header['alg'] === 'RS256'
                && $claims['iss'] === 'push@cnc-test.iam.gserviceaccount.com'
                && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging';
        });
    }

    public function test_no_request_is_made_for_an_account_without_a_device(): void
    {
        $this->configureFirebase();

        Notification::push($this->customer()->id, 'Order Confirmed', 'Good news!', 'order');

        Http::assertNothingSent();
    }

    public function test_without_firebase_credentials_the_notification_still_goes_out(): void
    {
        Http::fake();
        $user = $this->customer();
        $this->registerDevice($user, 'phone');

        $notification = Notification::push($user->id, 'Order Confirmed', 'Good news!', 'order');

        $this->assertTrue($notification->exists);
        Http::assertNothingSent();
    }

    public function test_a_token_fcm_reports_unregistered_is_deleted(): void
    {
        $this->configureFirebase();
        $user = $this->customer();
        $this->registerDevice($user, 'uninstalled');
        $this->registerDevice($user, 'alive');

        $this->failures['uninstalled'] = Http::response(['error' => [
            'code' => 404,
            'message' => 'Requested entity was not found.',
            'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
        ]], 404);

        Notification::push($user->id, 'Order Ready', 'Ready for collection.', 'order');

        $this->assertSame(['alive'], PushToken::pluck('token')->all());
    }

    public function test_a_failure_that_is_not_about_the_token_keeps_it(): void
    {
        $this->configureFirebase();
        $user = $this->customer();
        $this->registerDevice($user, 'phone');

        $this->failures['phone'] = Http::response(['error' => [
            'code' => 400,
            'message' => 'Invalid JSON payload received.',
            'status' => 'INVALID_ARGUMENT',
        ]], 400);

        Notification::push($user->id, 'Order Ready', 'Ready for collection.', 'order');

        $this->assertSame(1, PushToken::count());
    }

    public function test_an_account_wide_notice_is_pushed_to_every_customer_device_and_no_staff(): void
    {
        $this->configureFirebase();
        $this->registerDevice($this->customer(), 'customer-1');
        $this->registerDevice($this->customer(), 'customer-2');

        // Staff never get announcements: neither their own devices nor a
        // customer's token kept after being made staff.
        $this->registerDevice(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'account_status' => User::STATUS_ACTIVE]), 'manager');
        $promoted = $this->customer();
        $this->registerDevice($promoted, 'now-staff');
        $promoted->forceFill(['role' => User::ROLE_ADMIN])->save();

        (new NotifyEveryAccount('Price update', 'Spicy Fried Chicken is now ₱259.'))->handle();

        $this->assertEqualsCanonicalizing(['customer-1', 'customer-2'], array_column($this->sent, 'token'));
        $this->assertSame('Price update', $this->sent[0]['title']);
    }

    public function test_a_new_device_sign_in_alert_is_pushed(): void
    {
        $this->configureFirebase();
        $user = $this->customer();
        $this->registerDevice($user, 'old-phone');

        $device = KnownDevice::query()->create([
            'user_id' => $user->id,
            'device_fingerprint' => 'new',
            'device_name' => 'Firefox on Windows',
            'last_ip_address' => '203.0.113.9',
        ]);

        (new SendNewDeviceAlert($user->id, $device->id, '203.0.113.9'))->handle();

        $this->assertSame(['old-phone'], array_column($this->sent, 'token'));
        $this->assertSame('New sign-in detected', $this->sent[0]['title']);
        $this->assertSame('security', $this->sent[0]['data']['type']);
    }
}
