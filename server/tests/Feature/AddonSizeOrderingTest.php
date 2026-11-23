<?php

namespace Tests\Feature;

use App\Events\AddonBroadcast;
use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Events\PriceBroadcast;
use App\Models\Addon;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A customer picks an add-on in a size (decided 2026-11-24): the cart prices
 * it at that size, checkout stops one switched off since, and the order keeps
 * the size and its price for the kitchen and the receipt.
 */
class AddonSizeOrderingTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class, PriceBroadcast::class, AddonBroadcast::class]);
        Cache::flush();

        Setting::put(Setting::STORE_OPENS_AT, '07:00');
        Setting::put(Setting::STORE_CLOSES_AT, '20:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(): User
    {
        $phone = '+63917700'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'first_name' => 'Julia',
            'last_name' => 'Veneese',
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function dish(int $price = 200): Food
    {
        return Food::create([
            'food_name' => 'Chicken Inasal',
            'description' => 'Grilled.',
            'price' => $price,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
        ]);
    }

    /** Iced Tea in Small ₱45, Medium ₱55 and Large ₱65, offered with $food. */
    private function tea(Food $food): Addon
    {
        $tea = Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => 45, 'addon_group' => 'Drinks']);

        foreach (['small' => 45, 'medium' => 55, 'large' => 65] as $size => $price) {
            $tea->sizes()->create(['size' => $size, 'price' => $price]);
        }

        $tea->foods()->sync([$food->id]);

        return $tea;
    }

    private function add(User $user, Food $food, array $addons, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/cart/items', ['food_id' => $food->id, 'addons' => $addons] + $extra);
    }

    private function pickup(array $overrides = []): array
    {
        return array_merge([
            'fulfilment_type' => 'pickup',
            'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // The cart
    // -----------------------------------------------------------------

    public function test_an_add_on_is_priced_at_the_size_picked(): void
    {
        $user = $this->customer();
        $inasal = $this->dish(200);
        $tea = $this->tea($inasal);

        $response = $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']], ['quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('cart.0.addons.0.addon_name', 'Iced Tea (Large)')
            ->assertJsonPath('cart.0.addons.0.name', 'Iced Tea')
            ->assertJsonPath('cart.0.addons.0.size', 'large')
            ->assertJsonPath('cart.0.addons.0.size_label', 'Large');

        // (200 + 65) × 2
        $this->assertEquals(530, $response->json('subtotal'));
    }

    public function test_the_same_add_on_in_another_size_is_another_line(): void
    {
        $user = $this->customer();
        $inasal = $this->dish();
        $tea = $this->tea($inasal);

        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']])->assertCreated();
        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']])->assertCreated();
        $response = $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'small']])->assertCreated();

        $this->assertSame(2, $response->json('line_count'));
        $this->assertSame(3, $response->json('item_count'));
    }

    /** An app from before sizes sends addon_ids, with no size: it gets the smallest one available. */
    public function test_a_sized_add_on_sent_without_a_size_takes_the_smallest_available(): void
    {
        $user = $this->customer();
        $inasal = $this->dish();
        $tea = $this->tea($inasal);
        $tea->sizes()->where('size', 'small')->update(['is_available' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $inasal->id, 'addon_ids' => [$tea->id]])
            ->assertCreated()
            ->assertJsonPath('cart.0.addons.0.addon_name', 'Iced Tea (Medium)')
            ->assertJsonPath('cart.0.addons.0.addon_price', 55);
    }

    public function test_a_switched_off_size_is_refused_for_today_but_not_for_another_day(): void
    {
        $user = $this->customer();
        $inasal = $this->dish();
        $tea = $this->tea($inasal);
        $tea->sizes()->where('size', 'large')->update(['is_available' => false]);

        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'ADDON_UNAVAILABLE')
            ->assertJsonPath('message', 'Iced Tea (Large) is not available right now. Pick another size, or leave it off.');

        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']], ['mode' => 'advance'])
            ->assertCreated()
            ->assertJsonPath('cart.0.addons.0.addon_name', 'Iced Tea (Large)');
    }

    public function test_a_switched_off_add_on_is_refused(): void
    {
        $user = $this->customer();
        $inasal = $this->dish();
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides', 'availability' => false]);
        $fries->foods()->sync([$inasal->id]);

        $this->add($user, $inasal, [['id' => $fries->id]])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'ADDON_UNAVAILABLE');

        $this->assertSame(0, Order::count());
    }

    public function test_a_size_the_add_on_does_not_come_in_is_refused(): void
    {
        $user = $this->customer();
        $inasal = $this->dish();
        $tea = $this->tea($inasal);
        $tea->sizes()->where('size', 'large')->delete();

        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Iced Tea does not come in large. Pick another size.');

        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'jumbo']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['addons.0.size']);
    }

    // -----------------------------------------------------------------
    // Checkout and the order
    // -----------------------------------------------------------------

    public function test_checkout_stops_a_size_switched_off_after_it_went_in_the_cart(): void
    {
        $user = $this->customer();
        $inasal = $this->dish();
        $tea = $this->tea($inasal);

        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']])->assertCreated();

        $tea->sizes()->where('size', 'large')->update(['is_available' => false]);

        $this->actingAs($user, 'sanctum')->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('cart.0.unavailable_addons', ['Iced Tea (Large)'])
            ->assertJsonPath('has_unavailable_items', true);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'ADDON_UNAVAILABLE')
            ->assertJsonPath('blockers.0.message', 'Iced Tea (Large), on your Chicken Inasal, is not available right now. Remove that item and add it again without it.');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickup())
            ->assertStatus(409);

        $this->assertSame(0, Order::count());
    }

    public function test_the_order_keeps_the_size_and_its_price(): void
    {
        $user = $this->customer();
        $inasal = $this->dish(200);
        $tea = $this->tea($inasal);

        $this->add($user, $inasal, [['id' => $tea->id, 'size' => 'large']])->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['expected_total' => 265]))
            ->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame('265.00', $order->total_amount);

        $selection = OrderItemAddon::firstOrFail();
        $this->assertSame('large', $selection->size);
        $this->assertSame('65.00', $selection->unit_price);

        // A later price change does not reach it.
        $tea->sizes()->where('size', 'large')->update(['price' => 80]);

        // The payment screen reads it while it waits to be paid.
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/orders/{$order->id}/payment")
            ->assertOk()
            ->assertJsonPath('order.items.0.addons.0.addon_name', 'Iced Tea (Large)')
            ->assertJsonPath('order.items.0.addons.0.size', 'large')
            ->assertJsonPath('order.total', 265);
    }

    public function test_an_advance_order_is_paid_at_the_days_price_of_its_size(): void
    {
        $customer = $this->customer();
        $inasal = $this->dish(200);
        $tea = $this->tea($inasal);

        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
            'subtotal' => 265,
            'discount_amount' => 0,
            'delivery_fee' => 0,
            'total_amount' => 265,
            'total_price' => 265,
        ]);

        $item = OrderItem::create(['order_id' => $order->id, 'food_id' => $inasal->id, 'quantity' => 1, 'price' => 265, 'unit_price' => 265, 'subtotal' => 265]);
        $item->addonSelections()->create(['addon_id' => $tea->id, 'size' => 'large', 'unit_price' => 65]);

        $tea->sizes()->where('size', 'large')->update(['price' => 75]);
        $tea->sizes()->where('size', 'small')->update(['price' => 10]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true])
            ->assertOk();

        $this->assertSame('275.00', $order->fresh()->total_amount);
        $this->assertSame('75.00', $item->addonSelections()->first()->unit_price);
    }
}
