<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Policies\CartItemPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\FoodPolicy;
use App\Policies\OrderItemPolicy;
use App\Policies\OrderPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Policy-layer authorization: ownership rules and the two disjoint staff tiers.
 *
 * These exercise the Gate directly rather than going through HTTP, so they hold
 * even for abilities no controller calls yet. The role-boundary behaviour at
 * the route level is covered separately by RoleBoundaryTest.
 */
class PolicyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

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

    private function order(User $owner, string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $owner->id,
            'status' => $status,
            'total_price' => 120,
        ]);
    }

    // -----------------------------------------------------------------
    // BR-29 / FR-07.2 - stock is Store Agent only
    // -----------------------------------------------------------------

    public function test_only_the_store_agent_may_manage_stock(): void
    {
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('manageStock', Food::class));

        $this->assertFalse(
            Gate::forUser($this->user(User::ROLE_SUPER_ADMIN))->allows('manageStock', Food::class),
            'BR-29: the Store Manager must be fenced out of stock work.'
        );

        $this->assertFalse(Gate::forUser($this->user(User::ROLE_CUSTOMER))->allows('manageStock', Food::class));
    }

    public function test_only_the_store_agent_may_manage_availability(): void
    {
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('manageAvailability', Food::class));
        $this->assertFalse(Gate::forUser($this->user(User::ROLE_SUPER_ADMIN))->allows('manageAvailability', Food::class));
        $this->assertFalse(Gate::forUser($this->user(User::ROLE_CUSTOMER))->allows('manageAvailability', Food::class));
    }

    // -----------------------------------------------------------------
    // FR-07.6 - catalogue is Store Manager only
    // -----------------------------------------------------------------

    public function test_only_the_store_manager_may_manage_the_food_catalogue(): void
    {
        $food = $this->food();

        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        $agent = $this->user(User::ROLE_ADMIN);
        $customer = $this->user(User::ROLE_CUSTOMER);

        foreach (['create'] as $ability) {
            $this->assertTrue(Gate::forUser($manager)->allows($ability, Food::class));
            $this->assertFalse(Gate::forUser($agent)->allows($ability, Food::class));
            $this->assertFalse(Gate::forUser($customer)->allows($ability, Food::class));
        }

        foreach (['update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($manager)->allows($ability, $food));
            $this->assertFalse(Gate::forUser($agent)->allows($ability, $food));
            $this->assertFalse(Gate::forUser($customer)->allows($ability, $food));
        }
    }

    public function test_only_the_store_manager_may_manage_categories(): void
    {
        $category = Category::create(['name' => 'Drinks']);

        $this->assertTrue(Gate::forUser($this->user(User::ROLE_SUPER_ADMIN))->allows('delete', $category));
        $this->assertFalse(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('delete', $category));
        $this->assertFalse(
            Gate::forUser($this->user(User::ROLE_CUSTOMER))->allows('delete', $category),
            'Category writes previously had no role check at all.'
        );
    }

    public function test_the_menu_is_readable_by_every_role(): void
    {
        $food = $this->food();

        foreach (User::ROLES as $role) {
            $this->assertTrue(Gate::forUser($this->user($role))->allows('view', $food));
        }
    }

    // -----------------------------------------------------------------
    // Order ownership
    // -----------------------------------------------------------------

    public function test_a_customer_may_view_only_their_own_order(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);
        $stranger = $this->user(User::ROLE_CUSTOMER);
        $order = $this->order($owner);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $order));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $order));
    }

    public function test_both_staff_tiers_may_view_any_order(): void
    {
        $order = $this->order($this->user(User::ROLE_CUSTOMER));

        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('view', $order));
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_SUPER_ADMIN))->allows('view', $order));
    }

    public function test_the_order_queue_is_staff_only(): void
    {
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('viewAny', Order::class));
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_SUPER_ADMIN))->allows('viewAny', Order::class));
        $this->assertFalse(Gate::forUser($this->user(User::ROLE_CUSTOMER))->allows('viewAny', Order::class));
    }

    public function test_a_customer_may_cancel_their_own_order_only_before_confirmation(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);

        $pending = $this->order($owner, 'pending');
        $confirmed = $this->order($owner, 'confirmed');

        $this->assertTrue(Gate::forUser($owner)->allows('cancel', $pending), 'FR-02.8');
        $this->assertFalse(Gate::forUser($owner)->allows('cancel', $confirmed), 'FR-02.9: past confirmation this is an agent decision.');
    }

    public function test_a_customer_may_not_cancel_someone_elses_order(): void
    {
        $order = $this->order($this->user(User::ROLE_CUSTOMER), 'pending');

        $this->assertFalse(Gate::forUser($this->user(User::ROLE_CUSTOMER))->allows('cancel', $order));
    }

    public function test_staff_may_cancel_a_confirmed_order(): void
    {
        $order = $this->order($this->user(User::ROLE_CUSTOMER), 'confirmed');

        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('cancel', $order));
    }

    public function test_fulfilment_updates_are_staff_only(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);
        $order = $this->order($owner);

        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('update', $order));
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_SUPER_ADMIN))->allows('update', $order));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $order), 'A customer must not move their own order through fulfilment.');
    }

    public function test_receipt_confirmation_override_belongs_to_the_store_agent_alone(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);
        $order = $this->order($owner);

        $this->assertTrue(Gate::forUser($owner)->allows('confirmReceipt', $order));
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('confirmReceipt', $order), 'FR-02.10 override.');
        $this->assertFalse(Gate::forUser($this->user(User::ROLE_SUPER_ADMIN))->allows('confirmReceipt', $order));
    }

    public function test_no_role_may_delete_an_order(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);
        $order = $this->order($owner);

        foreach ([$owner, $this->user(User::ROLE_ADMIN), $this->user(User::ROLE_SUPER_ADMIN)] as $actor) {
            $this->assertFalse(Gate::forUser($actor)->allows('delete', $order));
        }
    }

    // -----------------------------------------------------------------
    // Order items inherit the parent order
    // -----------------------------------------------------------------

    public function test_order_items_follow_their_parent_orders_ownership(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);
        $stranger = $this->user(User::ROLE_CUSTOMER);

        $item = OrderItem::create([
            'order_id' => $this->order($owner)->id,
            'food_id' => $this->food()->id,
            'quantity' => 1,
            'price' => 120,
        ]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $item));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $item));
        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('view', $item));
    }

    // -----------------------------------------------------------------
    // Carts are private - staff get no elevated access
    // -----------------------------------------------------------------

    public function test_a_cart_item_is_reachable_only_by_its_owner(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);
        $stranger = $this->user(User::ROLE_CUSTOMER);

        $item = CartItem::create([
            'cart_id' => Cart::create(['user_id' => $owner->id])->id,
            'user_id' => $owner->id,
            'food_id' => $this->food()->id,
            'quantity' => 2,
        ]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $item));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $item));
    }

    public function test_staff_may_not_reach_another_users_cart(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);

        $item = CartItem::create([
            'cart_id' => Cart::create(['user_id' => $owner->id])->id,
            'user_id' => $owner->id,
            'food_id' => $this->food()->id,
            'quantity' => 1,
        ]);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $staff = $this->user($role);

            $this->assertFalse(Gate::forUser($staff)->allows('view', $item), "{$role} must not read another user's cart.");
            $this->assertFalse(Gate::forUser($staff)->allows('update', $item));
            $this->assertFalse(Gate::forUser($staff)->allows('delete', $item));
        }
    }

    public function test_cart_ownership_resolves_through_the_parent_cart(): void
    {
        $owner = $this->user(User::ROLE_CUSTOMER);

        // No user_id on the line itself; ownership must come from the cart row.
        $item = CartItem::create([
            'cart_id' => Cart::create(['user_id' => $owner->id])->id,
            'food_id' => $this->food()->id,
            'quantity' => 1,
        ]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $item));
        $this->assertFalse(Gate::forUser($this->user(User::ROLE_CUSTOMER))->allows('view', $item));
    }

    // -----------------------------------------------------------------
    // Account administration
    // -----------------------------------------------------------------

    public function test_only_the_store_manager_may_change_roles_and_never_their_own(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        $agent = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_CUSTOMER);

        $this->assertTrue(Gate::forUser($manager)->allows('updateRole', $target));
        $this->assertFalse(Gate::forUser($manager)->allows('updateRole', $manager), 'No self-applied role change.');
        $this->assertFalse(Gate::forUser($agent)->allows('updateRole', $target));
        $this->assertFalse(Gate::forUser($target)->allows('updateRole', $target));
    }

    public function test_a_user_may_view_and_update_their_own_account(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $stranger = $this->user(User::ROLE_CUSTOMER);

        $this->assertTrue(Gate::forUser($customer)->allows('view', $customer));
        $this->assertTrue(Gate::forUser($customer)->allows('update', $customer));
        $this->assertFalse(Gate::forUser($customer)->allows('view', $stranger));
        $this->assertFalse(Gate::forUser($customer)->allows('update', $stranger));
    }

    public function test_the_store_agent_does_not_inherit_account_administration(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_CUSTOMER);

        $this->assertFalse(Gate::forUser($agent)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($agent)->allows('view', $target));
        $this->assertFalse(Gate::forUser($agent)->allows('delete', $target));
    }

    // -----------------------------------------------------------------
    // Tier primitives
    // -----------------------------------------------------------------

    public function test_the_tier_primitives_do_not_form_a_ladder(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        $agent = $this->user(User::ROLE_ADMIN);

        $this->assertTrue(Gate::forUser($manager)->allows('isStoreManager', User::class));
        $this->assertFalse(
            Gate::forUser($manager)->allows('isStoreAgent', User::class),
            'A Store Manager must not satisfy a Store Agent check.'
        );

        $this->assertTrue(Gate::forUser($agent)->allows('isStoreAgent', User::class));
        $this->assertFalse(Gate::forUser($agent)->allows('isStoreManager', User::class));

        foreach ([$manager, $agent] as $staff) {
            $this->assertTrue(Gate::forUser($staff)->allows('isStaff', User::class));
        }
    }

    /** The names existing call sites depend on must keep resolving. */
    public function test_retained_ability_names_still_resolve(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        $agent = $this->user(User::ROLE_ADMIN);
        $customer = $this->user(User::ROLE_CUSTOMER);

        // OrderController fulfilment endpoints.
        $this->assertTrue(Gate::forUser($agent)->allows('isAdmin', Order::class));
        $this->assertTrue(Gate::forUser($manager)->allows('isAdmin', Order::class));
        $this->assertFalse(Gate::forUser($customer)->allows('isAdmin', Order::class));

        // FoodController catalogue writes.
        $this->assertTrue(Gate::forUser($manager)->allows('isSuperAdmin', Food::class));
        $this->assertFalse(Gate::forUser($agent)->allows('isSuperAdmin', Food::class));
    }

    public function test_every_policy_is_registered_with_the_gate(): void
    {
        $expected = [
            CartItem::class => CartItemPolicy::class,
            Category::class => CategoryPolicy::class,
            Food::class => FoodPolicy::class,
            Order::class => OrderPolicy::class,
            OrderItem::class => OrderItemPolicy::class,
            User::class => UserPolicy::class,
        ];

        foreach ($expected as $model => $policy) {
            $this->assertInstanceOf($policy, Gate::getPolicyFor($model), "No policy registered for {$model}.");
        }
    }
}
