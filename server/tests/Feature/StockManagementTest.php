<?php

namespace Tests\Feature;

use App\Events\MenuBroadcast;
use App\Http\Controllers\StockController;
use App\Models\Category;
use App\Models\Food;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Stock\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The Store Agent's stock page (BR-29, UC-MENU-016, UC-OPS-008): what it reads
 * and the only things it may change - the count and whether a dish is on.
 */
class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Classic Roasted Chicken',
            'description' => 'Whole.',
            'price' => 249,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => 5,
            'is_available' => true,
            'is_best_seller' => false,
        ], $overrides));
    }

    private function agent(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public function test_staff_see_every_dish_with_its_stock(): void
    {
        $chicken = Category::create(['name' => 'Chicken']);
        $this->food(['category_id' => $chicken->id, 'stock_quantity' => 12]);
        $this->food(['food_name' => 'Iced Tea', 'stock_quantity' => 3]);
        $this->food(['food_name' => 'Gravy', 'stock_quantity' => 0]);
        $this->food(['food_name' => 'Rice', 'stock_quantity' => null]);
        $this->food(['food_name' => 'Corn', 'is_available' => false]);

        $response = $this->actingAs($this->agent(), 'sanctum')
            ->getJson('/api/agent/stock')
            ->assertOk()
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.in_stock', 1)
            ->assertJsonPath('summary.low_stock', 2)
            ->assertJsonPath('summary.out_of_stock', 1)
            ->assertJsonPath('summary.untracked', 1)
            ->assertJsonPath('summary.switched_off', 1)
            ->assertJsonPath('meta.low_stock_threshold', Food::LOW_STOCK_THRESHOLD)
            ->assertJsonPath('meta.can_manage', true)
            ->assertJsonPath('categories.0.name', 'Chicken');

        // A switched-off dish is still listed: it is the one the agent turns back on.
        $corn = collect($response->json('data'))->firstWhere('food_name', 'Corn');
        $this->assertFalse($corn['is_available']);
        $this->assertFalse($corn['is_orderable']);

        $rice = collect($response->json('data'))->firstWhere('food_name', 'Rice');
        $this->assertFalse($rice['is_tracked']);
        $this->assertNull($rice['available_quantity']);
    }

    public function test_the_list_shows_units_held_for_customers_still_paying(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $order = Order::create([
            'user_id' => $this->user(User::ROLE_CUSTOMER)->id, 'order_type' => 'pickup', 'status' => OrderStatus::PLACED,
            'subtotal' => 0, 'total_amount' => 0, 'total_price' => 0, 'payment_status' => 'unpaid',
        ]);
        app(StockReservation::class)->hold($order, [['food_id' => $food->id, 'quantity' => 2]]);

        $item = $this->actingAs($this->agent(), 'sanctum')->getJson('/api/agent/stock')->json('data.0');

        $this->assertSame(5, $item['stock_quantity']);
        $this->assertSame(2, $item['held_quantity']);
        $this->assertSame(3, $item['available_quantity']);
    }

    public function test_the_store_manager_reads_the_page_but_is_told_it_cannot_change_it(): void
    {
        $this->food();

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson('/api/agent/stock')
            ->assertOk()
            ->assertJsonPath('meta.can_manage', false);
    }

    public function test_customers_and_guests_cannot_read_the_stock_page(): void
    {
        $this->getJson('/api/agent/stock')->assertUnauthorized();

        $this->actingAs($this->user(User::ROLE_CUSTOMER), 'sanctum')
            ->getJson('/api/agent/stock')
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Adjusting the count
    // -----------------------------------------------------------------

    public function test_the_agent_moves_the_count_up_and_down(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $agent = $this->agent();

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['adjust_by' => 3])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 8)
            ->assertJsonPath('item.stock_status', Food::STOCK_IN);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['adjust_by' => -20])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 0)
            ->assertJsonPath('item.is_orderable', false);
    }

    /** Relative, under the lock: a sale between reading and pressing + is kept. */
    public function test_a_step_does_not_undo_a_sale_that_landed_first(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $order = Order::create([
            'user_id' => $this->user(User::ROLE_CUSTOMER)->id, 'order_type' => 'pickup', 'status' => OrderStatus::PLACED,
            'subtotal' => 0, 'total_amount' => 0, 'total_price' => 0, 'payment_status' => 'unpaid',
        ]);

        // The agent's page still says 5 when a customer buys 2.
        $stock = app(StockReservation::class);
        $stock->hold($order, [['food_id' => $food->id, 'quantity' => 2]]);
        $stock->confirm($order);

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['adjust_by' => 1])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 4);
    }

    public function test_a_recount_is_refused_when_the_count_moved_meanwhile(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $food->forceFill(['stock_quantity' => 3])->save();

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 10, 'expected' => 5])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'STOCK_CHANGED')
            ->assertJsonPath('item.stock_quantity', 3);

        $this->assertSame(3, $food->fresh()->stock_quantity);

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 10, 'expected' => 3])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 10);
    }

    public function test_the_agent_switches_a_dish_between_counted_and_made_to_order(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $agent = $this->agent();

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => null])
            ->assertOk()
            ->assertJsonPath('item.is_tracked', false)
            ->assertJsonPath('item.stock_status', Food::STOCK_UNTRACKED);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['adjust_by' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STOCK_UNTRACKED');

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 8])
            ->assertOk()
            ->assertJsonPath('item.stock_quantity', 8);
    }

    public function test_a_change_must_say_what_it_is(): void
    {
        $food = $this->food();
        $agent = $this->agent();

        foreach ([[], ['adjust_by' => 1, 'stock_quantity' => 3], ['adjust_by' => 0], ['stock_quantity' => -1], ['stock_quantity' => StockController::MAX_COUNT + 1]] as $body) {
            $this->actingAs($agent, 'sanctum')
                ->patchJson("/api/foods/{$food->id}/stock", $body)
                ->assertStatus(422);
        }

        $this->assertSame(5, $food->fresh()->stock_quantity);
    }

    public function test_every_change_reaches_open_menus(): void
    {
        Event::fake([MenuBroadcast::class]);
        $food = $this->food();
        $agent = $this->agent();

        $this->actingAs($agent, 'sanctum')->patchJson("/api/foods/{$food->id}/stock", ['adjust_by' => -5])->assertOk();
        $this->actingAs($agent, 'sanctum')->patchJson("/api/foods/{$food->id}/availability", ['is_available' => false])->assertOk();

        Event::assertDispatchedTimes(MenuBroadcast::class, 2);
    }

    // -----------------------------------------------------------------
    // Availability, and the boundary
    // -----------------------------------------------------------------

    public function test_the_agent_switches_a_dish_off_and_on(): void
    {
        $food = $this->food();
        $agent = $this->agent();

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/availability", ['is_available' => false])
            ->assertOk()
            ->assertJsonPath('item.is_available', false)
            ->assertJsonPath('item.is_orderable', false)
            ->assertJsonPath('message', 'Classic Roasted Chicken is switched off.');

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/availability", ['is_available' => true])
            ->assertOk()
            ->assertJsonPath('item.is_orderable', true);
    }

    public function test_the_store_manager_cannot_step_the_count_either(): void
    {
        $food = $this->food();

        foreach ([['adjust_by' => 4], ['stock_quantity' => null]] as $body) {
            $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
                ->patchJson("/api/foods/{$food->id}/stock", $body)
                ->assertForbidden();
        }

        $this->assertSame(5, $food->fresh()->stock_quantity);
    }

    public function test_the_stock_endpoint_touches_nothing_but_the_count(): void
    {
        $food = $this->food();

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['adjust_by' => 1, 'price' => 1, 'food_name' => 'Free Chicken'])
            ->assertOk();

        $fresh = $food->fresh();
        $this->assertSame(249, $fresh->price);
        $this->assertSame('Classic Roasted Chicken', $fresh->food_name);
    }
}

