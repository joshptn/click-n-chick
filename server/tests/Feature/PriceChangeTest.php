<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Events\PriceBroadcast;
use App\Http\Controllers\CartController;
use App\Models\CartItem;
use App\Models\Discount;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Prices that change while a customer is buying (BR-18; decided 2026-11-24).
 *
 *   carts float     a cart is priced on every read, so it shows the new price
 *   shown total     placing, sending an advance request or paying with the
 *                   total the page showed is refused (PRICE_CHANGED) when the
 *                   total has moved since
 *   advance orders  paid at the prices of the day they are paid
 */
class PriceChangeTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class, PriceBroadcast::class]);
        Cache::flush();

        Setting::put(Setting::STORE_OPENS_AT, '07:00');
        Setting::put(Setting::STORE_CLOSES_AT, '20:00');

        $this->gateway = app(PaymentGateway::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(): User
    {
        $phone = '+63917600'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

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

    private function dish(string $name, int $price, bool $marked = false): Food
    {
        return Food::create([
            'food_name' => $name,
            'description' => 'A dish.',
            'price' => $price,
            'thumbnail' => 'https://example.test/dish.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'is_discountable' => $marked ? true : null,
            'prep_time' => 15,
        ]);
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

    /** An accepted advance request for $dish × $quantity, priced at $unit when it was sent. */
    private function acceptedRequest(User $customer, Food $dish, int $quantity, float $unit, float $discount = 0, float $vatExempt = 0): Order
    {
        $subtotal = $unit * $quantity;

        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'vat_exempt_amount' => $vatExempt,
            'delivery_fee' => 0,
            'total_amount' => $subtotal - $discount,
            'total_price' => $subtotal - $discount,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'food_id' => $dish->id,
            'quantity' => $quantity,
            'price' => $unit,
            'unit_price' => $unit,
            'subtotal' => $subtotal,
        ]);

        return $order;
    }

    // -----------------------------------------------------------------
    // Carts float
    // -----------------------------------------------------------------

    public function test_a_cart_shows_the_new_price_on_its_next_read(): void
    {
        $user = $this->customer();
        $spaghetti = $this->dish('Chicken Spaghetti', 180);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $spaghetti->id, 'quantity' => 2])
            ->assertCreated();

        $spaghetti->update(['price' => 200]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup())
            ->assertOk()
            ->assertJsonPath('subtotal', 400)
            ->assertJsonPath('total', 400);
    }

    // -----------------------------------------------------------------
    // The total the page showed
    // -----------------------------------------------------------------

    public function test_placing_with_an_old_total_is_refused_and_answers_the_new_one(): void
    {
        $user = $this->customer();
        $spaghetti = $this->dish('Chicken Spaghetti', 180);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $spaghetti->id, 'quantity' => 2])
            ->assertCreated();

        $spaghetti->update(['price' => 200]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['expected_total' => 360]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PRICE_CHANGED')
            ->assertJsonPath('message', 'Prices changed, so your total is now ₱400.00. Please check it, then try again.')
            ->assertJsonPath('quote.total', 400);

        $this->assertSame(0, Order::count());
        $this->assertSame(1, CartItem::count(), 'The cart is left as it was.');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['expected_total' => 400]))
            ->assertCreated();

        $this->assertSame('400.00', Order::firstOrFail()->total_amount);
    }

    public function test_placing_without_a_shown_total_goes_ahead_as_before(): void
    {
        $user = $this->customer();
        $spaghetti = $this->dish('Chicken Spaghetti', 180);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $spaghetti->id])
            ->assertCreated();

        $spaghetti->update(['price' => 200]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickup())
            ->assertCreated();

        $this->assertSame('200.00', Order::firstOrFail()->total_amount);
    }

    public function test_a_guest_placing_with_an_old_total_is_refused(): void
    {
        $spaghetti = $this->dish('Chicken Spaghetti', 180);

        $token = $this->postJson('/api/guest/cart/items', ['food_id' => $spaghetti->id])
            ->assertCreated()
            ->json('guest_token');

        $spaghetti->update(['price' => 190]);

        $this->withHeader(CartController::GUEST_HEADER, $token)
            ->postJson('/api/guest/orders', $this->pickup(['contact_email' => 'julia@example.test', 'expected_total' => 180]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PRICE_CHANGED')
            ->assertJsonPath('quote.total', 190);

        $this->assertSame(0, Order::count());
    }

    public function test_sending_an_advance_request_with_an_old_total_is_refused(): void
    {
        $user = $this->customer();
        $inasal = $this->dish('Chicken Inasal', 200);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $inasal->id, 'quantity' => 2, 'mode' => 'advance'])
            ->assertCreated();

        $inasal->update(['price' => 210]);
        $tomorrow = CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/advance-orders', ['scheduled_for' => $tomorrow, 'expected_total' => 400])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PRICE_CHANGED')
            ->assertJsonPath('quote.total', 420);

        $this->assertSame(0, Order::count());

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/advance-orders', ['scheduled_for' => $tomorrow, 'expected_total' => 420])
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // Advance orders are paid at the day's prices
    // -----------------------------------------------------------------

    public function test_an_advance_order_is_paid_at_the_price_of_the_day_it_is_paid(): void
    {
        $customer = $this->customer();
        $inasal = $this->dish('Chicken Inasal', 200);
        $order = $this->acceptedRequest($customer, $inasal, 2, 200);

        $inasal->update(['price' => 220]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true])
            ->assertOk()
            ->assertJsonPath('order.total', 440);

        $this->assertSame(440.0, $this->gateway->latest()->amount);

        $order->refresh();
        $this->assertSame('440.00', $order->subtotal);
        $this->assertSame('440.00', $order->total_amount);
        $this->assertSame('440.00', $order->total_price);
        $this->assertSame('220.00', $order->items()->first()->unit_price);
        $this->assertSame('440.00', $order->items()->first()->subtotal);

        Event::assertDispatched(OrderBroadcast::class);
    }

    public function test_paying_with_the_total_from_before_the_change_is_refused_with_the_new_one(): void
    {
        $customer = $this->customer();
        $inasal = $this->dish('Chicken Inasal', 200);
        $order = $this->acceptedRequest($customer, $inasal, 2, 200);

        $inasal->update(['price' => 180]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true, 'expected_total' => 400])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PRICE_CHANGED')
            ->assertJsonPath('order.total', 360);

        $this->assertNull($this->gateway->latest(), 'No payment page was opened.');
        $this->assertNull($order->fresh()->details_confirmed_at);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true, 'expected_total' => 360])
            ->assertOk();

        $this->assertSame(360.0, $this->gateway->latest()->amount);
    }

    public function test_the_discount_is_worked_out_again_on_the_new_price(): void
    {
        $customer = $this->customer();
        Discount::create([
            'user_id' => $customer->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_APPROVED,
            'verified_at' => now(),
        ]);
        $spaghetti = $this->dish('Chicken Spaghetti', 180, marked: true);

        // Sent at ₱180 × 2 = ₱360, one unit discounted: ₱19.29 + ₱32.14.
        $order = $this->acceptedRequest($customer, $spaghetti, 2, 180, 51.43, 19.29);

        $spaghetti->update(['price' => 210]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertOk();

        // ₱420, one ₱210 unit discounted: ₱22.50 VAT + ₱37.50 at 20% = ₱60.
        $order->refresh();
        $this->assertSame('420.00', $order->subtotal);
        $this->assertSame('60.00', $order->discount_amount);
        $this->assertSame('22.50', $order->vat_exempt_amount);
        $this->assertSame('360.00', $order->total_amount);
        $this->assertSame(360.0, $this->gateway->latest()->amount);
    }

    public function test_a_payment_page_opened_at_the_old_price_is_closed_before_a_new_one_opens(): void
    {
        $customer = $this->customer();
        $inasal = $this->dish('Chicken Inasal', 200);
        $order = $this->acceptedRequest($customer, $inasal, 1, 200);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $old = $this->gateway->latest();
        $this->assertSame(200.0, $old->amount);

        $inasal->update(['price' => 230]);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();

        $this->assertContains($old->id, $this->gateway->cancelled);
        $this->assertNotSame($old->id, $this->gateway->latest()->id);
        $this->assertSame(230.0, $this->gateway->latest()->amount);
    }

    public function test_a_payment_made_on_the_old_page_stands_at_the_old_price(): void
    {
        $customer = $this->customer();
        $inasal = $this->dish('Chicken Inasal', 200);
        $order = $this->acceptedRequest($customer, $inasal, 1, 200);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $this->gateway->pay($this->gateway->latest()->id);

        $inasal->update(['price' => 230]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ALREADY_PAID');

        $order->refresh();
        $this->assertTrue($order->isPaid());
        $this->assertSame('200.00', $order->total_amount);
    }

    public function test_an_unchanged_price_keeps_the_open_payment_page(): void
    {
        $customer = $this->customer();
        $inasal = $this->dish('Chicken Inasal', 200);
        $order = $this->acceptedRequest($customer, $inasal, 1, 200);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $first = $this->gateway->latest()->id;

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['expected_total' => 200])
            ->assertOk();

        $this->assertSame($first, $this->gateway->latest()->id);
        $this->assertSame([], $this->gateway->cancelled);
    }
}
