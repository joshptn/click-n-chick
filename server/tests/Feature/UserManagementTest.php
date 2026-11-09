<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\AuthEvent;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\Payment;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use App\Services\Media\CloudinaryService;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/** User Management (UC-ADMIN-001, 002): who may do what, and what it does. */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRM = ['password' => 'password'];

    private function user(string $role = User::ROLE_CUSTOMER, array $attributes = []): User
    {
        $phone = '+6391'.random_int(10000000, 99999999);

        return User::factory()->create($attributes + [
            'role' => $role,
            'account_status' => User::STATUS_ACTIVE,
            'verification_channel' => 'email',
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
        ])->fresh();
    }

    private function manager(): User
    {
        return $this->user(User::ROLE_SUPER_ADMIN);
    }

    private function as(User $actor, string $method, string $url, array $body = []): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->{$method}($url, $body);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'email' => 'ana@example.test',
            'phone_number' => '09171234567',
            'new_password' => 'Brand-New-1',
            'new_password_confirmation' => 'Brand-New-1',
            'role' => User::ROLE_ADMIN,
            'verification_channel' => 'email',
        ] + self::CONFIRM;
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_only_the_store_manager_reaches_any_endpoint(): void
    {
        $target = $this->user();
        $calls = [
            ['getJson', '/api/admin/users'],
            ['getJson', "/api/admin/users/{$target->id}"],
            ['postJson', '/api/admin/users'],
            ['patchJson', "/api/admin/users/{$target->id}/profile"],
            ['patchJson', "/api/admin/users/{$target->id}"],
            ['postJson', "/api/admin/users/{$target->id}/suspend"],
            ['postJson', "/api/admin/users/{$target->id}/reactivate"],
            ['deleteJson', "/api/admin/users/{$target->id}"],
        ];

        foreach ($calls as [$method, $url]) {
            $this->{$method}($url)->assertUnauthorized();
        }

        foreach ([$this->user(User::ROLE_ADMIN), $this->user()] as $actor) {
            foreach ($calls as [$method, $url]) {
                $this->as($actor, $method, $url, self::CONFIRM)->assertForbidden();
            }
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->account_status);
    }

    public function test_privileged_writes_need_the_managers_password(): void
    {
        $manager = $this->manager();
        $target = $this->user();

        foreach ([
            ['postJson', "/api/admin/users/{$target->id}/suspend", []],
            ['deleteJson', "/api/admin/users/{$target->id}", []],
            ['postJson', '/api/admin/users', array_diff_key($this->payload(), self::CONFIRM)],
        ] as [$method, $url, $body]) {
            $this->as($manager, $method, $url, $body)
                ->assertStatus(422)->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');
        }

        $this->as($manager, 'postJson', "/api/admin/users/{$target->id}/suspend", ['password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_INVALID');

        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->account_status);
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.test']);
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    public function test_responses_expose_no_credentials_and_only_a_masked_phone(): void
    {
        $manager = $this->manager();
        $target = $this->user(attributes: ['first_name' => 'Secret']);
        $target->createToken('t');

        $list = $this->as($manager, 'getJson', '/api/admin/users?search=secret')->assertOk()->assertJsonCount(1, 'data');
        $detail = $this->as($manager, 'getJson', "/api/admin/users/{$target->id}")->assertOk();

        foreach ([$list->json('data.0'), $detail->json('data')] as $row) {
            foreach (['password', 'remember_token', 'two_factor_secret', 'phone_number_hash', 'phone_number', 'phone', 'tokens'] as $field) {
                $this->assertArrayNotHasKey($field, $row);
            }

            $this->assertStringContainsString('*', $row['phone_masked']);
        }

        foreach ([$list, $detail] as $response) {
            $this->assertStringNotContainsString($target->phone_number, $response->getContent());
            $this->assertStringNotContainsString($target->password, $response->getContent());
        }
    }

    public function test_filters_search_pagination_and_summary(): void
    {
        $manager = $this->manager();
        $agent = $this->user(User::ROLE_ADMIN, ['first_name' => 'Agnes']);
        $suspended = $this->user(attributes: ['account_status' => User::STATUS_SUSPENDED]);
        $unverified = $this->user(attributes: ['email_verified_at' => null]);

        $ids = fn (string $query) => collect($this->as($manager, 'getJson', '/api/admin/users?'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$agent->id], $ids('role=admin'));
        $this->assertSame([$suspended->id], $ids('status=suspended'));
        $this->assertSame([$unverified->id], $ids('status=unverified'));
        $this->assertSame([$agent->id, $manager->id], $ids('status=active'));
        $this->assertSame([$agent->id], $ids('search=agnes'));
        $this->assertSame([$agent->id], $ids('search='.urlencode($agent->phone_number)));
        $this->assertSame([$agent->id], $ids('search=%23'.$agent->id));

        $this->as($manager, 'getJson', '/api/admin/users?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('summary.active', 2)
            ->assertJsonPath('summary.suspended', 1)
            ->assertJsonPath('summary.unverified', 1)
            ->assertJsonPath('summary.store_agents', 1);

        $this->as($manager, 'getJson', '/api/admin/users?status=banned')->assertUnprocessable();
    }

    public function test_discounts_reflect_approved_claims_only(): void
    {
        $manager = $this->manager();
        $make = fn (User $user, string $status) => Discount::create([
            'user_id' => $user->id, 'discount_type' => Discount::TYPE_PWD, 'id_image' => 'x', 'discount_status' => $status,
        ]);

        $approved = $this->user();
        $make($approved, Discount::STATUS_APPROVED);
        $make($this->user(), Discount::STATUS_PENDING);
        $make($this->user(), Discount::STATUS_REJECTED);

        $this->as($manager, 'getJson', '/api/admin/users?discount=eligible')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonPath('data.0.discount.type', Discount::TYPE_PWD)
            ->assertJsonPath('summary.discount_eligible', 1);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_a_manager_creates_an_account_that_must_still_verify(): void
    {
        $this->as($this->manager(), 'postJson', '/api/admin/users', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'unverified')
            ->assertJsonPath('data.role', User::ROLE_ADMIN);

        $created = User::where('email', 'ana@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('Brand-New-1', $created->password));
        $this->assertSame(User::STATUS_ACTIVE, $created->account_status, 'pending_verification would expose it to registration takeover.');
        $this->assertFalse($created->hasVerifiedChosenChannel());

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['login' => 'ana@example.test', 'password' => 'Brand-New-1'])
            ->assertForbidden()
            ->assertJsonPath('status', User::STATUS_PENDING_VERIFICATION)
            ->assertJsonMissingPath('token');
    }

    public function test_the_first_sign_in_sends_the_code_automatically_and_once(): void
    {
        Mail::fake();

        $this->as($this->manager(), 'postJson', '/api/admin/users', $this->payload())->assertCreated();
        $this->app['auth']->forgetGuards();
        Mail::assertNothingSent();

        $login = ['login' => 'ana@example.test', 'password' => 'Brand-New-1'];

        $this->postJson('/api/login', ['login' => 'ana@example.test', 'password' => 'wrong'])->assertUnauthorized();
        Mail::assertNothingSent();

        $this->postJson('/api/login', $login)
            ->assertForbidden()
            ->assertJsonPath('status', User::STATUS_PENDING_VERIFICATION)
            ->assertJsonPath('resend_available_in', OtpService::RESEND_COOLDOWN_SECONDS);
        Mail::assertSent(VerificationCodeMail::class, 1);

        // A second attempt inside the cooldown does not send another.
        $this->postJson('/api/login', $login)->assertForbidden();
        Mail::assertSent(VerificationCodeMail::class, 1);
    }

    public function test_signing_in_with_the_other_identifier_sends_nothing(): void
    {
        Mail::fake();

        $this->as($this->manager(), 'postJson', '/api/admin/users', $this->payload())->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['login' => '09171234567', 'password' => 'Brand-New-1'])
            ->assertForbidden()
            ->assertJsonPath('sign_in_with_channel', true);

        Mail::assertNothingSent();
    }

    public function test_public_registration_cannot_take_over_a_created_account(): void
    {
        $this->as($this->manager(), 'postJson', '/api/admin/users', $this->payload())->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/register', [
            'first_name' => 'Evil', 'last_name' => 'Twin', 'email' => 'ana@example.test',
            'password' => 'Takeover-1', 'password_confirmation' => 'Takeover-1', 'phone_number' => '09179999999',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('Brand-New-1', User::where('email', 'ana@example.test')->value('password')));
    }

    public function test_creation_validates_and_cannot_set_privileged_fields(): void
    {
        $manager = $this->manager();
        $this->user(attributes: ['email' => 'taken@example.test', 'phone_number_hash' => User::hashPhoneNumber('09170000000')]);

        $this->as($manager, 'postJson', '/api/admin/users', $this->payload(['email' => 'taken@example.test']))->assertJsonValidationErrors('email');
        $this->as($manager, 'postJson', '/api/admin/users', $this->payload(['phone_number' => '0917-000-0000']))->assertJsonValidationErrors('phone_number');
        $this->as($manager, 'postJson', '/api/admin/users', $this->payload(['new_password' => 'weak', 'new_password_confirmation' => 'weak']))->assertJsonValidationErrors('new_password');
        $this->as($manager, 'postJson', '/api/admin/users', $this->payload(['role' => 'wizard']))->assertJsonValidationErrors('role');

        $this->as($manager, 'postJson', '/api/admin/users', $this->payload([
            'account_status' => 'suspended', 'email_verified_at' => now()->toDateTimeString(), 'discount_status' => 'approved',
        ]))->assertCreated();

        $created = User::where('email', 'ana@example.test')->first();
        $this->assertSame(User::STATUS_ACTIVE, $created->account_status);
        $this->assertNull($created->email_verified_at);
        $this->assertFalse($created->isDiscountEligible());
    }

    // -----------------------------------------------------------------
    // Roles
    // -----------------------------------------------------------------

    public function test_a_role_change_ends_the_targets_sessions(): void
    {
        $target = $this->user();
        $target->createToken('old');

        $this->as($this->manager(), 'patchJson', "/api/admin/users/{$target->id}", ['role' => User::ROLE_ADMIN] + self::CONFIRM)
            ->assertOk()->assertJsonPath('data.role', User::ROLE_ADMIN);

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_the_last_active_manager_is_protected(): void
    {
        $actor = $this->manager();
        $last = $this->manager();
        // Neither of these could sign in, so neither counts as a survivor.
        $this->user(User::ROLE_SUPER_ADMIN, ['email_verified_at' => null]);
        $this->user(User::ROLE_SUPER_ADMIN, ['account_status' => User::STATUS_SUSPENDED]);

        // The actor loses standing mid-request, as in a concurrent change.
        $actor->forceFill(['account_status' => User::STATUS_SUSPENDED])->save();

        foreach ([
            ['patchJson', "/api/admin/users/{$last->id}", ['role' => User::ROLE_CUSTOMER]],
            ['postJson', "/api/admin/users/{$last->id}/suspend", []],
            ['deleteJson', "/api/admin/users/{$last->id}", []],
        ] as [$method, $url, $body]) {
            $this->as($actor, $method, $url, $body + self::CONFIRM)
                ->assertStatus(422)->assertJsonPath('error_code', 'LAST_MANAGER');
        }

        $this->assertSame(User::ROLE_SUPER_ADMIN, $last->fresh()->role);
        $this->assertSame(User::STATUS_ACTIVE, $last->fresh()->account_status);
    }

    public function test_a_manager_cannot_change_suspend_or_delete_themselves(): void
    {
        $actor = $this->manager();
        $this->manager();

        foreach ([
            ['patchJson', "/api/admin/users/{$actor->id}", ['role' => User::ROLE_CUSTOMER]],
            ['postJson', "/api/admin/users/{$actor->id}/suspend", []],
            ['postJson', "/api/admin/users/{$actor->id}/reactivate", []],
            ['deleteJson', "/api/admin/users/{$actor->id}", []],
        ] as [$method, $url, $body]) {
            $this->as($actor, $method, $url, $body + self::CONFIRM)
                ->assertStatus(422)->assertJsonPath('error_code', 'SELF_ACTION');
        }

        $this->as($actor, 'getJson', "/api/admin/users/{$actor->id}")
            ->assertJsonPath('data.can.change_role', false)
            ->assertJsonPath('data.can.suspend', false)
            ->assertJsonPath('data.can.delete', false);

        $this->assertSame(User::ROLE_SUPER_ADMIN, $actor->fresh()->role);
        $this->assertSame(User::STATUS_ACTIVE, $actor->fresh()->account_status);
    }

    public function test_a_pending_or_suspended_account_cannot_be_given_a_role(): void
    {
        $manager = $this->manager();

        foreach ([User::STATUS_PENDING_VERIFICATION, User::STATUS_SUSPENDED] as $status) {
            $target = $this->user(attributes: ['account_status' => $status]);

            $this->as($manager, 'patchJson', "/api/admin/users/{$target->id}", ['role' => User::ROLE_SUPER_ADMIN] + self::CONFIRM)->assertStatus(422);
            $this->assertSame(User::ROLE_CUSTOMER, $target->fresh()->role);
        }
    }

    // -----------------------------------------------------------------
    // Suspending
    // -----------------------------------------------------------------

    public function test_suspending_ends_sessions_and_can_be_undone(): void
    {
        $manager = $this->manager();
        $target = $this->user();
        $target->createToken('old');

        $this->as($manager, 'postJson', "/api/admin/users/{$target->id}/suspend", self::CONFIRM)
            ->assertOk()->assertJsonPath('data.status', User::STATUS_SUSPENDED)
            ->assertJsonPath('data.can.reactivate', true);

        $this->assertSame(0, $target->tokens()->count());

        $this->as($manager, 'postJson', "/api/admin/users/{$target->id}/reactivate", self::CONFIRM)
            ->assertOk()->assertJsonPath('data.status', User::STATUS_ACTIVE);
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        $target = $this->user(attributes: ['account_status' => User::STATUS_SUSPENDED, 'password' => 'Original-Pass1']);

        $this->postJson('/api/login', ['login' => $target->email, 'password' => 'Original-Pass1'])
            ->assertForbidden()->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED')->assertJsonMissingPath('token');

        // A wrong password learns nothing about the account's state.
        $this->postJson('/api/login', ['login' => $target->email, 'password' => 'nope'])->assertUnauthorized();
    }

    public function test_every_token_path_refuses_a_suspended_account(): void
    {
        $target = $this->user(attributes: ['account_status' => User::STATUS_SUSPENDED]);

        $this->expectException(HttpResponseException::class);

        app(DeviceRegistrar::class)->issueToken($target, Request::create('/'), 'x');
    }

    public function test_a_suspended_account_is_sent_no_codes(): void
    {
        $unverified = $this->user(attributes: ['account_status' => User::STATUS_SUSPENDED, 'email_verified_at' => null]);
        $verified = $this->user(attributes: ['account_status' => User::STATUS_SUSPENDED]);

        $this->postJson('/api/otp/resend', ['email' => $unverified->email])->assertOk();
        $this->postJson('/api/password/forgot', ['identifier' => $verified->email])->assertOk();

        $this->assertSame(0, OtpCode::query()->count());
        $this->assertTrue($unverified->fresh()->isSuspended());
    }

    public function test_a_name_correction_is_all_the_profile_route_changes(): void
    {
        $target = $this->user();

        $this->as($this->manager(), 'patchJson', "/api/admin/users/{$target->id}/profile", [
            'first_name' => 'New', 'last_name' => 'Name', 'email' => 'x@example.test', 'role' => 'super_admin', 'account_status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $fresh = $target->fresh();
        $this->assertSame($target->email, $fresh->email);
        $this->assertSame(User::ROLE_CUSTOMER, $fresh->role);
        $this->assertSame(User::STATUS_ACTIVE, $fresh->account_status);
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    private function ownedOrder(User $user, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'user_id' => $user->id,
            'order_type' => 'delivery',
            'status' => 'delivered',
            'subtotal' => 300,
            'delivery_fee' => 50,
            'total_price' => 350,
            'total_amount' => 350,
            'full_address' => '27 Rizal Street, Apalit',
            'payment_status' => 'paid',
        ]);
    }

    public function test_deleting_erases_the_person_and_keeps_the_financial_record(): void
    {
        $cloudinary = Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('delete')->once()->with('https://res.cloudinary.test/discount-ids/id.jpg', 'discount-ids');
        $cloudinary->shouldReceive('delete')->once()->with('https://res.cloudinary.test/avatars/me.jpg', 'avatars');
        $this->app->instance(CloudinaryService::class, $cloudinary);

        $manager = $this->manager();
        $target = $this->user(attributes: ['first_name' => 'Maria', 'email' => 'maria@example.test', 'avatar' => 'https://res.cloudinary.test/avatars/me.jpg']);
        $target->createToken('old');
        $order = $this->ownedOrder($target, ['updated_at' => now()->subWeek()]);
        $payment = Payment::create(['order_id' => $order->id, 'user_id' => $target->id, 'payment_status' => 'paid', 'paid_at' => now()]);
        Discount::create(['user_id' => $target->id, 'discount_type' => Discount::TYPE_SENIOR, 'id_image' => 'https://res.cloudinary.test/discount-ids/id.jpg', 'discount_status' => Discount::STATUS_APPROVED]);
        AuthEvent::create(['user_id' => $target->id, 'event_type' => 'new_device_login', 'ip_address' => '203.0.113.9']);

        $updatedAt = $order->fresh()->getRawOriginal('updated_at');

        $this->as($manager, 'deleteJson', "/api/admin/users/{$target->id}", self::CONFIRM)->assertOk();

        // The person is gone.
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('users', ['email' => 'maria@example.test']);
        $this->assertSame(0, Discount::query()->count());
        $this->assertSame(0, AuthEvent::query()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id, 'tokenable_type' => User::class]);

        // The record is not, and it no longer points at anyone.
        $kept = $order->fresh();
        $this->assertNull($kept->user_id);
        $this->assertSame(Order::ANONYMIZED_CUSTOMER, $kept->guest_name);
        $this->assertNull($kept->guest_phone);
        $this->assertNull($kept->guest_email);
        $this->assertSame('350.00', $kept->total_amount);
        $this->assertSame('delivered', $kept->status);
        $this->assertSame('27 Rizal Street, Apalit', $kept->full_address);
        $this->assertSame($updatedAt, $kept->getRawOriginal('updated_at'));
        $this->assertNull($payment->fresh()->user_id);

        $this->as($manager, 'getJson', '/api/agent/records/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.customer.name', Order::ANONYMIZED_CUSTOMER)
            ->assertJsonPath('data.customer.is_guest', false)
            ->assertJsonPath('data.total', 350);
    }

    public function test_a_deleted_accounts_orders_cannot_be_opened_as_a_guest(): void
    {
        $target = $this->user();
        $order = $this->ownedOrder($target);

        $this->as($this->manager(), 'deleteJson', "/api/admin/users/{$target->id}", self::CONFIRM)->assertOk();

        $this->assertNull($order->fresh()->guest_token_hash);
    }

    public function test_a_deleted_agents_cancellations_stay_the_stores_but_anonymous(): void
    {
        $manager = $this->manager();
        $agent = $this->user(User::ROLE_ADMIN, ['first_name' => 'Jake', 'last_name' => 'Dorwart']);
        $order = $this->ownedOrder($this->user(), ['status' => 'cancelled', 'cancelled_by' => $agent->id]);
        $own = $this->ownedOrder($agent, ['status' => 'cancelled', 'cancelled_by' => $agent->id]);
        $date = now('Asia/Manila')->toDateString();

        $this->as($manager, 'deleteJson', "/api/admin/users/{$agent->id}", self::CONFIRM)->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $agent->id]);
        $this->assertSame(User::DELETED_STAFF_EMAIL, $order->fresh()->cancelledBy->email);
        $this->assertNull($own->fresh()->cancelled_by, 'Cancelling their own order was not the store acting.');

        $this->as($manager, 'getJson', '/api/admin/reports/daily-sales?date='.$date)
            ->assertJsonPath('not_sold.cancelled.by_store', 1)
            ->assertJsonPath('not_sold.cancelled.by_customer', 1);

        $record = $this->as($manager, 'getJson', '/api/agent/records/'.$order->id)->assertOk();
        $record->assertJsonPath('data.cancelled_by_store', true)->assertJsonPath('data.cancelled_by_name', 'Deleted staff');
        $this->assertStringNotContainsString('Jake', $record->getContent());
    }

    public function test_the_deleted_staff_placeholder_is_not_an_account(): void
    {
        $manager = $this->manager();
        $make = fn () => $this->user(User::ROLE_ADMIN);

        foreach ([$make(), $make()] as $agent) {
            $this->ownedOrder($this->user(), ['status' => 'cancelled', 'cancelled_by' => $agent->id]);
            $this->as($manager, 'deleteJson', "/api/admin/users/{$agent->id}", self::CONFIRM)->assertOk();
        }

        $placeholder = User::where('email', User::DELETED_STAFF_EMAIL)->sole();

        $this->assertTrue($placeholder->isSuspended());
        $this->as($manager, 'getJson', "/api/admin/users/{$placeholder->id}")->assertNotFound();
        $this->as($manager, 'deleteJson', "/api/admin/users/{$placeholder->id}", self::CONFIRM)->assertNotFound();
        $this->as($manager, 'postJson', "/api/admin/users/{$placeholder->id}/reactivate", self::CONFIRM)->assertNotFound();

        $this->as($manager, 'getJson', '/api/admin/users?search=deleted')->assertJsonCount(0, 'data');
        $this->as($manager, 'getJson', '/api/admin/users')->assertJsonPath('summary.store_agents', 0)->assertJsonPath('summary.suspended', 0);
    }

    public function test_an_account_with_an_open_order_cannot_be_deleted(): void
    {
        $target = $this->user();
        $order = $this->ownedOrder($target, ['status' => 'preparing']);

        $this->as($this->manager(), 'deleteJson', "/api/admin/users/{$target->id}", self::CONFIRM)
            ->assertStatus(422)->assertJsonPath('error_code', 'HAS_OPEN_ORDERS');

        $this->assertNotNull($target->fresh());
        $this->assertSame($target->id, $order->fresh()->user_id);
    }

    public function test_the_email_and_phone_are_free_again_after_deletion(): void
    {
        $manager = $this->manager();
        $target = $this->user(attributes: ['email' => 'ana@example.test', 'phone_number_hash' => User::hashPhoneNumber('09171234567')]);

        $this->as($manager, 'deleteJson', "/api/admin/users/{$target->id}", self::CONFIRM)->assertOk();
        $this->as($manager, 'postJson', '/api/admin/users', $this->payload())->assertCreated();
    }
}
