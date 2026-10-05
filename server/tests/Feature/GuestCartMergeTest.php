<?php

namespace Tests\Feature;

use App\Http\Controllers\CartController;
use App\Models\Addon;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens to a guest cart afterwards: folded into the account on sign-in,
 * or deleted once nobody has come back for it.
 *
 * The merge is additive and must count once, however many times it is asked -
 * a second tab or a retry is the normal case, not the edge. And the fence that
 * keeps a token away from an account's cart has to hold here too, because this
 * is the one place a guest cart is read while somebody is signed in.
 */
class GuestCartMergeTest extends TestCase
{
    use RefreshDatabase;

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Herb Roasted Chicken',
            'description' => 'Whole.',
            'price' => 350,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => 20,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 20,
        ], $overrides));
    }

    private function addon(string $name): Addon
    {
        return Addon::create([
            'addon_name' => $name,
            'addon_price' => 25,
            'availability' => true,
        ]);
    }

    private function line(Cart $cart, Food $food, int $quantity = 1, array $addonIds = []): CartItem
    {
        $line = $cart->items()->create([
            'user_id' => $cart->user_id,
            'food_id' => $food->id,
            'quantity' => $quantity,
        ]);

        $line->selectedAddons()->sync($addonIds);

        return $line;
    }

    private function accountCart(User $user): Cart
    {
        return Cart::firstOrCreate(['user_id' => $user->id, 'cart_status' => Cart::STATUS_IMMEDIATE]);
    }

    private function merge(User $user, ?string $token)
    {
        $request = $this->actingAs($user, 'sanctum');

        if ($token !== null) {
            $request = $request->withHeader(CartController::GUEST_HEADER, $token);
        }

        return $request->postJson('/api/cart/merge');
    }

    // -----------------------------------------------------------------
    // Merging on sign-in
    // -----------------------------------------------------------------

    public function test_a_guest_cart_is_folded_into_the_account(): void
    {
        $guest = Cart::startForGuest();
        $this->line($guest, $this->food(), 2);
        $this->line($guest, $this->food(['food_name' => 'Buttered Corn', 'price' => 60]));

        $user = User::factory()->create();

        $this->merge($user, $guest->guest_token)
            ->assertOk()
            ->assertJsonPath('merged', 2)
            ->assertJsonPath('line_count', 2)
            ->assertJsonPath('item_count', 3);

        $this->assertNull(Cart::find($guest->id), 'The guest cart is spent once merged.');
        $this->assertNull(Cart::forGuestToken($guest->guest_token));
    }

    public function test_the_moved_lines_belong_to_the_account_afterwards(): void
    {
        $guest = Cart::startForGuest();
        $line = $this->line($guest, $this->food());

        $user = User::factory()->create();

        $this->merge($user, $guest->guest_token)->assertOk();

        $moved = $line->fresh();

        $this->assertSame($this->accountCart($user)->id, $moved->cart_id);
        $this->assertSame($user->id, $moved->user_id);
    }

    public function test_a_line_the_account_already_has_absorbs_the_guest_quantity(): void
    {
        $chicken = $this->food();
        $user = User::factory()->create();

        $this->line($this->accountCart($user), $chicken, 1);

        $guest = Cart::startForGuest();
        $this->line($guest, $chicken, 2);

        $this->merge($user, $guest->guest_token)
            ->assertOk()
            ->assertJsonPath('line_count', 1)
            ->assertJsonPath('cart.0.quantity', 3);
    }

    public function test_the_same_food_with_different_add_ons_stays_a_separate_line(): void
    {
        $chicken = $this->food();
        $gravy = $this->addon('Gravy');
        $user = User::factory()->create();

        $this->line($this->accountCart($user), $chicken, 1);

        $guest = Cart::startForGuest();
        $this->line($guest, $chicken, 1, [$gravy->id]);

        $this->merge($user, $guest->guest_token)
            ->assertOk()
            ->assertJsonPath('line_count', 2);
    }

    public function test_add_ons_come_across_with_the_line(): void
    {
        $gravy = $this->addon('Gravy');
        $guest = Cart::startForGuest();
        $this->line($guest, $this->food(), 1, [$gravy->id]);

        $user = User::factory()->create();

        $this->merge($user, $guest->guest_token)
            ->assertOk()
            ->assertJsonPath('cart.0.addons.0.addon_name', 'Gravy');
    }

    public function test_a_merged_quantity_is_held_to_stock(): void
    {
        $chicken = $this->food(['stock_quantity' => 4]);
        $user = User::factory()->create();

        $this->line($this->accountCart($user), $chicken, 3);

        $guest = Cart::startForGuest();
        $this->line($guest, $chicken, 3);

        $this->merge($user, $guest->guest_token)
            ->assertOk()
            ->assertJsonPath('cart.0.quantity', 4);
    }

    /**
     * A second tab, or a retry after a dropped response, must not double the cart.
     *
     * Uses a line the account already has on purpose: an absorbed guest line stays
     * attached to the guest cart, so deleting that cart is the only thing that
     * stops the second call absorbing it again. A moved line would leave the
     * guest cart empty either way and prove nothing.
     */
    public function test_merging_twice_counts_once(): void
    {
        $chicken = $this->food();
        $user = User::factory()->create();

        $this->line($this->accountCart($user), $chicken, 1);

        $guest = Cart::startForGuest();
        $this->line($guest, $chicken, 2);

        // Counts lines, not items: one line of two.
        $this->merge($user, $guest->guest_token)
            ->assertOk()
            ->assertJsonPath('merged', 1)
            ->assertJsonPath('cart.0.quantity', 3);

        $this->merge($user, $guest->guest_token)
            ->assertOk()
            ->assertJsonPath('merged', 0)
            ->assertJsonPath('cart.0.quantity', 3);
    }

    public function test_without_a_guest_token_nothing_changes(): void
    {
        $user = User::factory()->create();
        $this->line($this->accountCart($user), $this->food(), 2);

        $this->merge($user, null)
            ->assertOk()
            ->assertJsonPath('merged', 0)
            ->assertJsonPath('cart.0.quantity', 2);
    }

    /** The fence: a token must never let one account absorb another's cart. */
    public function test_an_account_cart_can_never_be_merged_away(): void
    {
        $victim = User::factory()->create();

        // A row the application never writes but the schema allows: an owned cart
        // that also carries a token.
        $owned = Cart::create([
            'user_id' => $victim->id,
            'guest_token' => 'stolen-token',
            'cart_status' => Cart::STATUS_IMMEDIATE,
        ]);
        $this->line($owned, $this->food(), 2);

        $attacker = User::factory()->create();

        $this->merge($attacker, 'stolen-token')
            ->assertOk()
            ->assertJsonPath('merged', 0)
            ->assertJsonPath('item_count', 0);

        $this->assertNotNull(Cart::find($owned->id));
        $this->assertSame(1, $owned->items()->count());
    }

    public function test_merging_needs_a_session(): void
    {
        $guest = Cart::startForGuest();
        $this->line($guest, $this->food());

        $this->withHeader(CartController::GUEST_HEADER, $guest->guest_token)
            ->postJson('/api/cart/merge')
            ->assertUnauthorized();

        $this->assertNotNull(Cart::find($guest->id));
    }

    // -----------------------------------------------------------------
    // Pruning guest carts nobody came back for
    // -----------------------------------------------------------------

    private function guestCartFrom(int $daysAgo): array
    {
        $this->travelTo(now()->subDays($daysAgo));

        $cart = Cart::startForGuest();
        $line = $this->line($cart, $this->food(['food_name' => 'Chicken '.$daysAgo.' '.uniqid()]));

        $this->travelBack();

        return [$cart, $line];
    }

    public function test_an_idle_guest_cart_is_deleted_with_its_lines(): void
    {
        [$cart, $line] = $this->guestCartFrom(10);

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNull(Cart::find($cart->id));
        $this->assertNull(CartItem::find($line->id), 'Its lines go with it.');
    }

    public function test_a_recent_guest_cart_is_kept(): void
    {
        [$cart] = $this->guestCartFrom(2);

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNotNull(Cart::find($cart->id));
    }

    /** Changing a line does not touch the cart, so the lines have to count as activity. */
    public function test_an_old_cart_with_a_recently_changed_line_is_kept(): void
    {
        [$cart, $line] = $this->guestCartFrom(10);

        $line->update(['quantity' => 3]);

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNotNull(Cart::find($cart->id));
    }

    public function test_an_account_cart_is_never_pruned(): void
    {
        $user = User::factory()->create();

        $this->travelTo(now()->subDays(60));
        $cart = $this->accountCart($user);
        $this->line($cart, $this->food());
        $this->travelBack();

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNotNull(Cart::find($cart->id));
    }

    public function test_the_window_comes_from_configuration(): void
    {
        config()->set('store.guest.cart_idle_days', 1);

        [$cart] = $this->guestCartFrom(2);

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNull(Cart::find($cart->id));
    }

    public function test_the_dry_run_deletes_nothing(): void
    {
        [$cart] = $this->guestCartFrom(10);

        $this->artisan('guest:prune-carts --dry-run')->assertSuccessful();

        $this->assertNotNull(Cart::find($cart->id));
    }
}
