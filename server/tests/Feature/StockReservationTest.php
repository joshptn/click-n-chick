<?php

namespace Tests\Feature;

use App\Events\MenuBroadcast;
use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Exceptions\InsufficientStock;
use App\Models\Food;
use App\Models\Order;
use App\Models\StockHold;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Stock\StockReservation;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * How orders take stock and give it back (GATE-7).
 *
 * Until payment exists, placing an order is the sale: the units come off the
 * count under a row lock. A cancellation returns them while the kitchen has not
 * started. Holds - units set aside while a customer pays - count against what
 * is left without touching the count, and lapse on their own.
 */
class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function user(string $role = User::ROLE_CUSTOMER): User
    {
        $phone = '+63917400'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

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

    private function body(): array
    {
        return [
            'fulfilment_type' => 'pickup',
            'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
        ];
    }

    private function cart(User $customer, Food $food, int $quantity): void
    {
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => $quantity])
            ->assertCreated();
    }

    private function place(User $customer)
    {
        return $this->actingAs($customer, 'sanctum')->postJson('/api/order/place', $this->body());
    }

    private function placed(User $customer, Food $food, int $quantity): Order
    {
        $this->cart($customer, $food, $quantity);
        $this->place($customer)->assertCreated();

        return Order::query()->where('user_id', $customer->id)->latest('id')->firstOrFail();
    }

    private function bareOrder(): Order
    {
        return Order::create([
            'user_id' => $this->user()->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 0,
            'total_amount' => 0,
            'total_price' => 0,
            'payment_status' => 'unpaid',
        ]);
    }

    // -----------------------------------------------------------------
    // Taking stock
    // -----------------------------------------------------------------

    public function test_placing_an_order_takes_its_units_off_the_count(): void
    {
        $food = $this->food(['stock_quantity' => 10]);

        $order = $this->placed($this->user(), $food, 3);

        $this->assertSame(7, $food->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_holds', [
            'order_id' => $order->id,
            'food_id' => $food->id,
            'quantity' => 3,
            'status' => StockHold::CONFIRMED,
        ]);
    }

    public function test_the_last_units_go_to_one_customer(): void
    {
        $food = $this->food(['stock_quantity' => 2]);
        $first = $this->user();
        $second = $this->user();

        // Both carts were filled while two were left.
        $this->cart($first, $food, 2);
        $this->cart($second, $food, 2);

        $this->place($first)->assertCreated();

        $this->place($second)->assertStatus(409);

        $this->assertSame(0, $food->fresh()->stock_quantity);
        $this->assertSame(1, Order::count());
    }

    public function test_the_lock_refuses_more_than_is_left_and_takes_nothing(): void
    {
        $food = $this->food(['stock_quantity' => 2]);
        $order = $this->bareOrder();

        try {
            app(StockReservation::class)->hold($order, [['food_id' => $food->id, 'quantity' => 3]]);
            $this->fail('Three were held when two were left.');
        } catch (InsufficientStock $short) {
            $this->assertSame(2, $short->shortages[0]['available']);
            $this->assertStringContainsString('Only 2 Herb Roasted Chicken left', $short->getMessage());
        }

        $this->assertSame(0, StockHold::count());
        $this->assertSame(2, $food->fresh()->stock_quantity);
    }

    public function test_one_dish_on_two_lines_is_counted_together(): void
    {
        $food = $this->food(['stock_quantity' => 3]);

        // Two lines of the same dish with different add-ons.
        $this->expectException(InsufficientStock::class);

        app(StockReservation::class)->hold($this->bareOrder(), [
            ['food_id' => $food->id, 'quantity' => 2],
            ['food_id' => $food->id, 'quantity' => 2],
        ]);
    }

    public function test_a_switched_off_dish_is_refused_at_the_lock(): void
    {
        $food = $this->food(['is_available' => false]);

        $this->expectException(InsufficientStock::class);

        app(StockReservation::class)->hold($this->bareOrder(), [['food_id' => $food->id, 'quantity' => 1]]);
    }

    public function test_a_quote_flags_an_order_for_more_than_is_left(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $customer = $this->user();
        $this->cart($customer, $food, 5);

        // The kitchen recounts after the cart was filled.
        $food->forceFill(['stock_quantity' => 3])->save();

        $blockers = collect(
            $this->actingAs($customer, 'sanctum')->postJson('/api/checkout/quote', $this->body())->assertOk()->json('blockers')
        );

        $this->assertSame(
            'Only 3 Herb Roasted Chicken left. Lower the quantity to continue.',
            $blockers->firstWhere('code', 'ITEM_STOCK_SHORT')['message'] ?? null
        );

        $this->place($customer)->assertStatus(409)->assertJsonPath('error_code', 'ITEM_STOCK_SHORT');
        $this->assertSame(3, $food->fresh()->stock_quantity);
    }

    public function test_a_made_to_order_dish_is_never_held(): void
    {
        $food = $this->food(['stock_quantity' => null]);

        $this->placed($this->user(), $food, 40);

        $this->assertNull($food->fresh()->stock_quantity);
        $this->assertSame(0, StockHold::count());
    }

    public function test_open_menus_hear_about_a_sale(): void
    {
        Event::fake([MenuBroadcast::class, OrderBroadcast::class, NotificationBroadcast::class]);
        $food = $this->food(['stock_quantity' => 4]);

        $this->placed($this->user(), $food, 4);

        Event::assertDispatched(
            MenuBroadcast::class,
            fn (MenuBroadcast $event) => $event->food->id === $food->id && $event->food->stock_status === Food::STOCK_OUT
        );
    }

    // -----------------------------------------------------------------
    // Giving it back
    // -----------------------------------------------------------------

    public function test_cancelling_before_the_kitchen_starts_returns_the_units(): void
    {
        $food = $this->food(['stock_quantity' => 10]);
        $customer = $this->user();
        $order = $this->placed($customer, $food, 3);

        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();

        $this->assertSame(10, $food->fresh()->stock_quantity);
        $this->assertSame(StockHold::RELEASED, $order->stockHolds()->value('status'));
    }

    public function test_an_agent_cancelling_a_confirmed_order_returns_the_units(): void
    {
        $food = $this->food(['stock_quantity' => 10]);
        $order = $this->placed($this->user(), $food, 3);
        $order->forceFill(['status' => OrderStatus::CONFIRMED])->save();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Out of stock'])
            ->assertOk();

        $this->assertSame(10, $food->fresh()->stock_quantity);
    }

    public function test_once_the_kitchen_starts_a_cancellation_keeps_the_units_sold(): void
    {
        $food = $this->food(['stock_quantity' => 10]);
        $order = $this->placed($this->user(), $food, 3);
        $order->forceFill(['status' => OrderStatus::PREPARING])->save();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Customer left'])
            ->assertOk();

        $this->assertSame(7, $food->fresh()->stock_quantity);
        $this->assertSame(StockHold::CONFIRMED, $order->stockHolds()->value('status'));
    }

    public function test_a_returned_sale_is_returned_once(): void
    {
        $food = $this->food(['stock_quantity' => 10]);
        $order = $this->placed($this->user(), $food, 3);

        $order->forceFill(['status' => OrderStatus::CANCELLED])->save();
        app(StockReservation::class)->release($order->fresh(), true);

        $this->assertSame(10, $food->fresh()->stock_quantity);
    }

    // -----------------------------------------------------------------
    // Holds: set aside while the customer pays (FR-04.5)
    // -----------------------------------------------------------------

    public function test_a_hold_counts_against_what_is_left_without_touching_the_count(): void
    {
        $food = $this->food(['stock_quantity' => 5]);

        app(StockReservation::class)->hold($this->bareOrder(), [['food_id' => $food->id, 'quantity' => 4]]);

        $fresh = $food->fresh();
        $this->assertSame(5, $fresh->stock_quantity);
        $this->assertSame(1, $fresh->available_quantity);
        $this->assertSame(Food::STOCK_LOW, $fresh->stock_status);

        // A second customer cannot take the held units.
        $this->expectException(InsufficientStock::class);
        app(StockReservation::class)->hold($this->bareOrder(), [['food_id' => $food->id, 'quantity' => 2]]);
    }

    public function test_a_fully_held_dish_reads_sold_out_on_the_menu(): void
    {
        $food = $this->food(['stock_quantity' => 2]);

        app(StockReservation::class)->hold($this->bareOrder(), [['food_id' => $food->id, 'quantity' => 2]]);

        $listed = collect($this->getJson('/api/foods')->assertOk()->json('data'))->firstWhere('id', $food->id);
        $this->assertFalse($listed['is_orderable']);
        $this->assertSame(Food::STOCK_OUT, $listed['stock_status']);

        $this->assertSame([], collect($this->getJson('/api/foods?orderable=1')->json('data'))->pluck('id')->all());
    }

    public function test_paying_confirms_the_hold_and_takes_it_off_the_count(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $order = $this->bareOrder();
        $stock = app(StockReservation::class);

        $stock->hold($order, [['food_id' => $food->id, 'quantity' => 2]]);
        $stock->confirm($order);

        $this->assertSame(3, $food->fresh()->stock_quantity);
        $this->assertSame(3, $food->fresh()->available_quantity);
        $this->assertSame(StockHold::CONFIRMED, $order->stockHolds()->value('status'));
    }

    public function test_an_unpaid_hold_lapses_and_the_sweep_records_it(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        app(StockReservation::class)->hold($this->bareOrder(), [['food_id' => $food->id, 'quantity' => 5]]);

        $this->assertSame(0, $food->fresh()->available_quantity);

        $this->travel(StockReservation::PAYMENT_HOLD_MINUTES + 1)->minutes();

        // Already free before the sweep runs; the sweep only tidies.
        $this->assertSame(5, $food->fresh()->available_quantity);

        $this->artisan('stock:expire-holds')->assertSuccessful();

        $this->assertSame(StockHold::EXPIRED, StockHold::query()->value('status'));
        $this->assertSame(5, $food->fresh()->stock_quantity);
    }

    public function test_cancelling_an_unpaid_hold_drops_it_without_touching_the_count(): void
    {
        $food = $this->food(['stock_quantity' => 5]);
        $order = $this->bareOrder();
        app(StockReservation::class)->hold($order, [['food_id' => $food->id, 'quantity' => 2]]);

        $order->forceFill(['status' => OrderStatus::CANCELLED])->save();

        $this->assertSame(5, $food->fresh()->stock_quantity);
        $this->assertSame(5, $food->fresh()->available_quantity);
        $this->assertSame(StockHold::RELEASED, $order->stockHolds()->value('status'));
    }
}
