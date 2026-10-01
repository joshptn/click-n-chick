<?php

namespace Tests\Feature;

use App\Http\Controllers\CartController;
use App\Models\Cart;
use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guest cart (UC-GUEST-002).
 *
 * Two properties carry the weight. The token is a bucket key rather than a
 * credential, so it is minted server-side and must never resolve to an account's
 * cart however it was come by. And an unauthenticated read creates nothing, so
 * the only public endpoint that can write a row is the one that adds an item.
 */
class GuestCartTest extends TestCase
{
    use RefreshDatabase;

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Herb Roasted Chicken',
            'description' => 'Whole.',
            'price' => 350,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 20,
        ], $overrides));
    }

    /** @return array{0: string, 1: int} the minted token and the line id */
    private function guestWithCart(?Food $food = null): array
    {
        $food ??= $this->food();

        $response = $this->postJson('/api/guest/cart/items', ['food_id' => $food->id])
            ->assertCreated();

        return [$response->json('guest_token'), $response->json('cart_item_id')];
    }

    public function test_a_first_add_mints_a_cart_and_hands_back_its_token(): void
    {
        [$token] = $this->guestWithCart();

        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $cart = Cart::firstOrFail();

        $this->assertNull($cart->user_id);
        $this->assertSame($token, $cart->guest_token);
    }

    public function test_replaying_the_token_returns_the_same_cart(): void
    {
        [$token] = $this->guestWithCart();

        $this->withHeader(CartController::GUEST_HEADER, $token)
            ->getJson('/api/guest/cart')
            ->assertOk()
            ->assertJsonCount(1, 'cart')
            ->assertJsonPath('cart.0.food_name', 'Herb Roasted Chicken')
            ->assertJsonPath('item_count', 1);

        $this->assertSame(1, Cart::count(), 'Replaying a token must not start a second cart.');
    }

    public function test_adding_again_with_the_token_builds_on_the_same_cart(): void
    {
        [$token] = $this->guestWithCart();

        $this->withHeader(CartController::GUEST_HEADER, $token)
            ->postJson('/api/guest/cart/items', ['food_id' => $this->food(['food_name' => 'Buttered Corn'])->id])
            ->assertCreated()
            ->assertJsonCount(2, 'cart');

        $this->assertSame(1, Cart::count());
    }

    public function test_reading_without_a_token_creates_nothing(): void
    {
        $this->getJson('/api/guest/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 0)
            ->assertJsonPath('guest_token', null)
            ->assertJsonCount(0, 'cart');

        $this->assertSame(0, Cart::count(), 'An anonymous read must not be able to create rows.');
    }

    public function test_an_unknown_token_reads_as_an_empty_cart_rather_than_someone_elses(): void
    {
        $this->guestWithCart();

        $this->withHeader(CartController::GUEST_HEADER, 'not-a-real-token')
            ->getJson('/api/guest/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 0);
    }

    /** The guard that matters: the lookup is fenced to carts with no owner. */
    public function test_a_guest_token_cannot_resolve_to_an_account_cart(): void
    {
        $user = User::factory()->create();

        // A row the application never writes but the schema allows: an owned cart
        // that also carries a token. Without the whereNull, presenting that token
        // anonymously would hand back somebody's account cart.
        $cart = Cart::create([
            'user_id' => $user->id,
            'guest_token' => 'stolen-token',
            'cart_status' => Cart::STATUS_IMMEDIATE,
        ]);

        $cart->items()->create([
            'user_id' => $user->id,
            'food_id' => $this->food(['food_name' => 'Account Chicken'])->id,
            'quantity' => 1,
        ]);

        $this->withHeader(CartController::GUEST_HEADER, 'stolen-token')
            ->getJson('/api/guest/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 0);
    }

    public function test_a_signed_in_request_ignores_a_guest_token(): void
    {
        [$token] = $this->guestWithCart($this->food(['food_name' => 'Guest Chicken']));

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food(['food_name' => 'Account Chicken'])->id])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->withHeader(CartController::GUEST_HEADER, $token)
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(1, 'cart')
            ->assertJsonPath('cart.0.food_name', 'Account Chicken');
    }

    public function test_one_guest_cannot_touch_another_guests_line(): void
    {
        [, $lineId] = $this->guestWithCart();
        [$otherToken] = $this->guestWithCart($this->food(['food_name' => 'Buttered Corn']));

        $this->withHeader(CartController::GUEST_HEADER, $otherToken)
            ->patchJson("/api/guest/cart/items/{$lineId}", ['quantity' => 9])
            ->assertForbidden();

        $this->withHeader(CartController::GUEST_HEADER, $otherToken)
            ->deleteJson("/api/guest/cart/items/{$lineId}")
            ->assertForbidden();
    }

    public function test_a_line_cannot_be_touched_without_any_token(): void
    {
        [, $lineId] = $this->guestWithCart();

        $this->patchJson("/api/guest/cart/items/{$lineId}", ['quantity' => 9])
            ->assertForbidden();
    }

    public function test_a_guest_can_change_a_quantity_and_remove_a_line(): void
    {
        [$token, $lineId] = $this->guestWithCart();

        $this->withHeader(CartController::GUEST_HEADER, $token)
            ->patchJson("/api/guest/cart/items/{$lineId}", ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('item_count', 3);

        $this->withHeader(CartController::GUEST_HEADER, $token)
            ->deleteJson("/api/guest/cart/items/{$lineId}")
            ->assertOk()
            ->assertJsonPath('item_count', 0);
    }

    public function test_a_guest_cannot_open_an_advance_cart(): void
    {
        $this->postJson('/api/guest/cart/items', [
            'food_id' => $this->food()->id,
            'mode' => Cart::MODE_ADVANCE,
        ])->assertForbidden();

        $this->assertSame(0, Cart::count());
    }

    public function test_the_signed_in_cart_routes_still_need_a_session(): void
    {
        $this->getJson('/api/cart')->assertUnauthorized();
        $this->postJson('/api/cart/items', ['food_id' => $this->food()->id])->assertUnauthorized();
    }
}
