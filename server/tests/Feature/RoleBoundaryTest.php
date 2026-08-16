<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Server-side role boundaries (PRD §7, FR-01.12).
 *
 * The two tiers are non-overlapping in BOTH directions, which is the property
 * these tests exist to hold down:
 *
 *   FR-07.6  catalogue  -> Store Manager only, Store Agent fenced out
 *   BR-29    stock      -> Store Agent only, Store Manager fenced out
 *
 * A hierarchy regression ("super_admin can do anything") would pass a naive
 * permissions test suite and silently break BR-29, so the Manager-denied cases
 * are asserted explicitly rather than inferred.
 */
class RoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // MenuBroadcast implements ShouldBroadcast; keep the tests off the wire.
        Event::fake();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = User::STATUS_ACTIVE;
        $user->save();

        return $user->fresh();
    }

    private function food(): Food
    {
        return Food::create([
            'thumbnail' => 'https://example.test/chicken.png',
            'food_name' => 'Fried Chicken',
            'price' => 120,
            'description' => 'One piece with rice.',
            'stock_quantity' => 10,
            'is_available' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // BR-29 / FR-07.2 - stock is Store Agent only
    // -----------------------------------------------------------------

    public function test_store_agent_can_update_stock(): void
    {
        $food = $this->food();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 3])
            ->assertOk();

        $this->assertSame(3, $food->fresh()->stock_quantity);
    }

    public function test_store_agent_can_toggle_availability(): void
    {
        $food = $this->food();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/availability", ['is_available' => false])
            ->assertOk();

        $this->assertFalse($food->fresh()->is_available);
    }

    public function test_store_manager_is_fenced_out_of_stock_updates(): void
    {
        $food = $this->food();

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 999])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ROLE_FORBIDDEN');

        $this->assertSame(10, $food->fresh()->stock_quantity, 'BR-29: the Store Manager must not be able to move stock.');
    }

    public function test_store_manager_is_fenced_out_of_availability_toggles(): void
    {
        $food = $this->food();

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/availability", ['is_available' => false])
            ->assertForbidden();

        $this->assertTrue($food->fresh()->is_available);
    }

    public function test_customer_is_fenced_out_of_stock_updates(): void
    {
        $food = $this->food();

        $this->actingAs($this->user(User::ROLE_CUSTOMER), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 999])
            ->assertForbidden();

        $this->assertSame(10, $food->fresh()->stock_quantity);
    }

    public function test_guest_is_rejected_from_stock_updates_as_unauthenticated(): void
    {
        $food = $this->food();

        $this->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 999])
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // FR-07.6 - catalogue is Store Manager only
    // -----------------------------------------------------------------

    public static function catalogueRoutes(): array
    {
        return [
            'create item' => ['postJson', '/api/foods'],
            'create category' => ['postJson', '/api/category'],
        ];
    }

    /**
     * @dataProvider catalogueRoutes
     */
    public function test_store_agent_is_fenced_out_of_catalogue_management(string $verb, string $uri): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->{$verb}($uri, [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ROLE_FORBIDDEN');
    }

    public function test_store_agent_is_fenced_out_of_catalogue_deletion(): void
    {
        $food = $this->food();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->deleteJson("/api/foods/{$food->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('food', ['id' => $food->id]);
    }

    public function test_store_manager_may_delete_a_catalogue_item(): void
    {
        $food = $this->food();

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->deleteJson("/api/foods/{$food->id}")
            ->assertOk();

        $this->assertDatabaseMissing('food', ['id' => $food->id]);
    }

    /**
     * The Manager reaches the controller (a 422 for the empty body), rather
     * than being turned away by the role gate.
     */
    public function test_store_manager_reaches_catalogue_creation(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->postJson('/api/foods', [])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Shared staff routes
    // -----------------------------------------------------------------

    public function test_both_staff_roles_may_list_all_orders(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $this->actingAs($this->user($role), 'sanctum')
                ->getJson('/api/orders/all')
                ->assertOk();
        }
    }

    public function test_customer_cannot_list_all_orders(): void
    {
        $this->actingAs($this->user(User::ROLE_CUSTOMER), 'sanctum')
            ->getJson('/api/orders/all')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ROLE_FORBIDDEN');
    }

    public function test_guest_cannot_list_all_orders(): void
    {
        $this->getJson('/api/orders/all')->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Store Manager only - user administration
    // -----------------------------------------------------------------

    public function test_store_agent_cannot_reach_user_administration(): void
    {
        $target = $this->user(User::ROLE_CUSTOMER);

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'super_admin'])
            ->assertForbidden();

        $this->assertSame(User::ROLE_CUSTOMER, $target->fresh()->role);
    }

    // -----------------------------------------------------------------
    // Any authenticated role
    // -----------------------------------------------------------------

    public function test_every_role_may_read_its_own_profile(): void
    {
        foreach (User::ROLES as $role) {
            $this->actingAs($this->user($role), 'sanctum')
                ->getJson('/api/user')
                ->assertOk();
        }
    }

    public function test_guest_cannot_read_a_profile(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Middleware semantics
    // -----------------------------------------------------------------

    public function test_the_gate_matches_exactly_and_does_not_inherit(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->assertTrue($manager->hasRole(User::ROLE_SUPER_ADMIN));
        $this->assertFalse(
            $manager->hasRole(User::ROLE_ADMIN),
            'A role ladder here would silently defeat BR-29.'
        );
        $this->assertTrue($manager->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN));
    }

    public function test_an_unknown_role_in_a_route_definition_is_rejected(): void
    {
        Route::middleware(['auth:sanctum', 'role:wizard'])
            ->get('/api/_test/unknown-role', fn () => response()->json(['ok' => true]));

        $this->withoutExceptionHandling();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown role(s) in route middleware: wizard');

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->getJson('/api/_test/unknown-role');
    }
}
