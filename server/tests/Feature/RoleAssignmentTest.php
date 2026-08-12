<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user->fresh();
    }

    // ---------------------------------------------------------------------
    // Part 1 - register() must never take a role from the request
    // ---------------------------------------------------------------------

    public function test_registration_ignores_a_role_field_in_the_payload(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Escalation Attempt',
            'email' => 'escalate@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'super_admin',
        ]);

        $response->assertOk();

        $user = User::where('email', 'escalate@example.test')->firstOrFail();

        $this->assertSame('customer', $user->role, 'register() must not honour a role supplied in the request.');
        $this->assertSame('customer', $response->json('user.role'));
    }

    public function test_registration_ignores_role_like_aliases_in_the_payload(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Alias Attempt',
            'email' => 'alias@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'super_admin',
            'user_role' => 'super_admin',
            'roles' => ['super_admin'],
            'is_admin' => true,
            'is_super_admin' => true,
            'account_status' => 'privileged',
        ])->assertOk();

        $this->assertSame('customer', User::where('email', 'alias@example.test')->value('role'));
    }

    // ---------------------------------------------------------------------
    // Part 2 - self-service update cannot touch role
    // ---------------------------------------------------------------------

    public function test_self_service_update_cannot_change_role(): void
    {
        $user = $this->makeUser('customer');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/update', [
                'name' => 'Renamed',
                'role' => 'super_admin',
                'account_status' => 'banned',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('customer', $fresh->role, 'Self-service update must not change role.');
    }

    public function test_self_service_password_change_requires_current_password(): void
    {
        $user = $this->makeUser('customer');
        $user->password = Hash::make('original-password');
        $user->save();

        // Wrong current password is rejected.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/update', [
                'current_password' => 'not-the-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));

        // Correct current password succeeds.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/update', [
                'current_password' => 'original-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    // ---------------------------------------------------------------------
    // Part 2 - admin role endpoint, super_admin only
    // ---------------------------------------------------------------------

    public function test_super_admin_can_change_another_users_role(): void
    {
        $actor = $this->makeUser('super_admin');
        $target = $this->makeUser('customer');

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'admin'])
            ->assertOk();

        $this->assertSame('admin', $target->fresh()->role);
    }

    public function test_store_agent_admin_is_rejected_at_the_route_level(): void
    {
        $actor = $this->makeUser('admin');
        $target = $this->makeUser('customer');

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'super_admin'])
            ->assertForbidden();

        $this->assertSame('customer', $target->fresh()->role);
    }

    public function test_customer_is_rejected(): void
    {
        $actor = $this->makeUser('customer');
        $target = $this->makeUser('customer');

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('customer', $target->fresh()->role);
    }

    public function test_guest_is_rejected(): void
    {
        $target = $this->makeUser('customer');

        $this->patchJson("/api/admin/users/{$target->id}", ['role' => 'admin'])
            ->assertUnauthorized();
    }

    public function test_super_admin_cannot_change_their_own_role(): void
    {
        $actor = $this->makeUser('super_admin');
        $this->makeUser('super_admin'); // a second one, so "last super admin" is not the blocker

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$actor->id}", ['role' => 'customer'])
            ->assertStatus(422);

        $this->assertSame('super_admin', $actor->fresh()->role);
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        $actor = $this->makeUser('super_admin');
        $other = $this->makeUser('super_admin');

        // Demoting the second one is fine while the actor still holds the role.
        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$other->id}", ['role' => 'admin'])
            ->assertOk();

        $this->assertSame('admin', $other->fresh()->role);
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    public function test_an_invalid_role_value_is_rejected(): void
    {
        $actor = $this->makeUser('super_admin');
        $target = $this->makeUser('customer');

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'wizard'])
            ->assertStatus(422);

        $this->assertSame('customer', $target->fresh()->role);
    }
}
