<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Store\StoreAvailability;
use App\Services\Verification\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Who may change trading hours, and who may pause trading (UC-OPS-009..011).
 *
 * The split is the point. Hours are standing policy and belong to the Store
 * Manager; the pause switches are an operational reaction to today and belong
 * to both staff tiers (FR-07.3). BR-29's separation of governance from
 * operations is what these assert.
 */
class StoreOperationsSettingTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    private function userWithRole(string $role): User
    {
        $phone = '+63917300'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'role' => $role,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    // -----------------------------------------------------------------
    // UC-OPS-011: hours are the Store Manager's alone
    // -----------------------------------------------------------------

    public function test_the_store_manager_can_set_the_operating_hours(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->putJson('/api/admin/settings/store', ['opens_at' => '08:00', 'closes_at' => '21:00'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status.opens_at', '08:00')
            ->assertJsonPath('status.closes_at', '21:00');

        $this->assertSame('08:00', app(StoreAvailability::class)->opensAt());
    }

    public function test_a_store_agent_cannot_set_the_operating_hours(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_ADMIN), 'sanctum')
            ->putJson('/api/admin/settings/store', ['opens_at' => '08:00', 'closes_at' => '21:00'])
            ->assertStatus(403);
    }

    public function test_a_customer_cannot_read_or_set_the_operating_hours(): void
    {
        $customer = $this->userWithRole(User::ROLE_CUSTOMER);

        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/settings/store')->assertStatus(403);
        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/admin/settings/store', ['opens_at' => '08:00', 'closes_at' => '21:00'])
            ->assertStatus(403);
    }

    public function test_hours_must_be_a_valid_time_and_must_differ(): void
    {
        $manager = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/store', ['opens_at' => '25:00', 'closes_at' => '21:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('opens_at');

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/store', ['opens_at' => '08:00', 'closes_at' => '08:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('closes_at');
    }

    // -----------------------------------------------------------------
    // UC-OPS-009 / 010: both staff tiers may pause
    // -----------------------------------------------------------------

    public function test_a_store_agent_can_pause_online_ordering(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_ADMIN), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['ordering_override' => 'closed'])
            ->assertOk()
            ->assertJsonPath('status.accepting_orders', false);
    }

    public function test_a_store_agent_can_pause_delivery_alone(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_ADMIN), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['delivery_enabled' => false])
            ->assertOk()
            ->assertJsonPath('status.delivery_enabled', false);

        $this->assertFalse(app(StoreAvailability::class)->deliveryEnabled());
    }

    public function test_the_store_manager_can_pause_too(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['delivery_enabled' => false])
            ->assertOk();
    }

    public function test_a_customer_cannot_pause_anything(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_CUSTOMER), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['ordering_override' => 'closed'])
            ->assertStatus(403);
    }

    public function test_an_unknown_override_value_is_refused(): void
    {
        $this->actingAs($this->userWithRole(User::ROLE_ADMIN), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['ordering_override' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ordering_override');
    }

    public function test_either_toggle_may_be_sent_on_its_own(): void
    {
        $agent = $this->userWithRole(User::ROLE_ADMIN);

        $this->actingAs($agent, 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['delivery_enabled' => false])
            ->assertOk();

        $this->actingAs($agent, 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['ordering_override' => 'open'])
            ->assertOk()
            ->assertJsonPath('status.delivery_enabled', false, 'The untouched toggle must survive.');
    }

    public function test_resuming_returns_the_store_to_its_scheduled_hours(): void
    {
        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_CLOSED);

        $this->actingAs($this->userWithRole(User::ROLE_ADMIN), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['ordering_override' => 'auto'])
            ->assertOk()
            ->assertJsonPath('status.ordering_override', 'auto');
    }
}
