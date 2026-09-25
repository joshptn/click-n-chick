<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Food;
use App\Models\User;
use App\Services\Verification\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The advance cart (BR-16d).
 *
 * A customer keeps two carts at once, told apart by cart_status rather than by
 * any new column. The advance one is deliberately blind to stock: today's
 * availability says nothing about a collection date up to a month away, and
 * whether the kitchen can produce the quantity is answered by the Store Agent
 * accepting the request (BR-16e), not by a count taken while the customer is
 * still building the order.
 */
class AdvanceCartTest extends TestCase
{
    use RefreshDatabase;

    /** Distinct per call: phone_number_hash is uniquely indexed. */
    private int $phoneSeq = 0;

    private function customer(): User
    {
        $phone = '+63918000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Chicken Inasal',
            'description' => 'Charcoal grilled.',
            'price' => 160,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 15,
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // The two carts are separate
    // -----------------------------------------------------------------

    public function test_advance_and_immediate_carts_are_separate(): void
    {
        $user = $this->customer();
        $food = $this->food();

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 2,
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 7,
            'mode' => 'advance',
        ])->assertCreated();

        $this->actingAs($user)->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('mode', 'immediate')
            ->assertJsonPath('item_count', 2);

        $this->actingAs($user)->getJson('/api/cart?mode=advance')
            ->assertOk()
            ->assertJsonPath('mode', 'advance')
            ->assertJsonPath('item_count', 7);
    }

    public function test_the_second_cart_needs_no_schema_change(): void
    {
        $user = $this->customer();
        $food = $this->food();

        $this->actingAs($user)->postJson('/api/cart/items', ['food_id' => $food->id]);
        $this->actingAs($user)->postJson('/api/cart/items', ['food_id' => $food->id, 'mode' => 'advance']);

        $this->assertDatabaseHas('cart', [
            'user_id' => $user->id,
            'cart_status' => Cart::STATUS_IMMEDIATE,
        ]);

        $this->assertDatabaseHas('cart', [
            'user_id' => $user->id,
            'cart_status' => Cart::STATUS_ADVANCE,
        ]);
    }

    public function test_an_unrecognised_mode_falls_back_to_the_immediate_cart(): void
    {
        $user = $this->customer();
        $food = $this->food();

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'mode' => 'immediate',
        ])->assertCreated();

        $this->actingAs($user)->getJson('/api/cart?mode=something-else')
            ->assertOk()
            ->assertJsonPath('mode', 'immediate')
            ->assertJsonPath('item_count', 1);
    }

    // -----------------------------------------------------------------
    // Advance ordering ignores stock (BR-16d)
    // -----------------------------------------------------------------

    public function test_a_sold_out_item_can_still_be_ordered_in_advance(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 0]);

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'mode' => 'advance',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
        ])->assertStatus(422)->assertJsonPath('error_code', 'FOOD_UNAVAILABLE');
    }

    public function test_an_unavailable_item_can_still_be_ordered_in_advance(): void
    {
        $user = $this->customer();
        $food = $this->food(['is_available' => false]);

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'mode' => 'advance',
        ])->assertCreated();
    }

    public function test_advance_quantities_are_not_clamped_to_stock(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 3]);

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 80,
            'mode' => 'advance',
        ])->assertCreated()->assertJsonPath('item_count', 80);
    }

    public function test_immediate_quantities_are_still_clamped_to_stock(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 3]);

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 80,
        ])->assertCreated()->assertJsonPath('item_count', 3);
    }

    public function test_advance_lines_report_no_stock_state(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 1]);

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'mode' => 'advance',
        ]);

        $this->actingAs($user)->getJson('/api/cart?mode=advance')
            ->assertOk()
            ->assertJsonPath('cart.0.is_orderable', true)
            ->assertJsonPath('cart.0.stock_status', null)
            ->assertJsonPath('cart.0.stock_quantity', null)
            ->assertJsonPath('has_unavailable_items', false);
    }

    // -----------------------------------------------------------------
    // Editing a line uses the cart the line is actually in
    // -----------------------------------------------------------------

    public function test_updating_an_advance_line_keeps_it_in_the_advance_cart(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 2]);

        $created = $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'mode' => 'advance',
        ])->json();

        // No mode on the request at all: the line's own cart decides, so the
        // quantity is not clamped to the two in stock.
        $this->actingAs($user)->patchJson("/api/cart/items/{$created['cart_item_id']}", [
            'quantity' => 40,
        ])->assertOk()
            ->assertJsonPath('mode', 'advance')
            ->assertJsonPath('item_count', 40);

        $this->actingAs($user)->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 0);
    }

    public function test_clearing_one_cart_leaves_the_other_alone(): void
    {
        $user = $this->customer();
        $food = $this->food();

        $this->actingAs($user)->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 2]);
        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 5,
            'mode' => 'advance',
        ]);

        $this->actingAs($user)->deleteJson('/api/cart', ['mode' => 'advance'])
            ->assertOk()
            ->assertJsonPath('item_count', 0);

        $this->actingAs($user)->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 2);
    }
}
