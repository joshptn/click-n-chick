<?php

namespace Tests\Feature;

use App\Events\AddonBroadcast;
use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Addon;
use App\Models\Discount;
use App\Models\Food;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Loyalty\LoyaltyPoints;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentSettlement;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The BES Loyalty Program (decided 2026-11-24).
 *
 *   earning    a customer account's paid order, once completed or delivered,
 *              earns 1% of its food at menu prices, VAT out, before the SC/PWD
 *              discount, without the dishes paid with points or the delivery
 *              fee; centavos kept
 *   redeeming  a dish is paid with points at its menu price (1 point = ₱1),
 *              its add-ons with money; the points are held from placing (or,
 *              for an advance order, from opening its payment) and come back
 *              if it is cancelled; a ₱0 order skips the payment page
 *   reporting  issued, redeemed and outstanding for the Store Manager, kept
 *              through an account deletion
 */
class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class, AddonBroadcast::class]);
        Cache::flush();

        Setting::put(Setting::STORE_OPENS_AT, '07:00');
        Setting::put(Setting::STORE_CLOSES_AT, '20:00');

        $this->gateway = app(PaymentGateway::class);
        config(['services.xendit.webhook_token' => 'test-callback-token']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function customer(float $points = 0, string $role = User::ROLE_CUSTOMER): User
    {
        $phone = '+63917700'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'role' => $role,
            'first_name' => 'Julia',
            'last_name' => 'Veneese',
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
            'loyalty_points' => $points,
        ])->fresh();
    }

    private function dish(string $name = 'Chicken Inasal', int $price = 160, bool $marked = false): Food
    {
        return Food::create([
            'food_name' => $name,
            'description' => 'A dish.',
            'price' => $price,
            'thumbnail' => 'https://example.test/dish.jpg',
            'stock_quantity' => 20,
            'is_available' => true,
            'is_best_seller' => false,
            'is_discountable' => $marked ? true : null,
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

    /** Adds to the cart and returns the cart line's id. */
    private function addToCart(User $user, Food $food, int $quantity = 1, array $addons = []): int
    {
        return (int) $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => $quantity, 'addons' => $addons])
            ->assertCreated()
            ->json('cart_item_id');
    }

    private function lineId(User $user, Food $food): int
    {
        return (int) $user->carts()->first()->items()->where('food_id', $food->id)->value('id');
    }

    private function balance(User $user): float
    {
        return (float) $user->fresh()->loyalty_points;
    }

    /** The customer pays the order's open checkout, and the payment is confirmed. */
    private function payCheckout(User $user, Order $order): void
    {
        $this->gateway->pay((string) $order->payments()->latest('id')->value('provider_reference_id'));

        $this->actingAs($user, 'sanctum')->getJson("/api/orders/{$order->id}/payment")->assertOk();
    }

    /** A paid immediate order, as placed, for earning on. */
    private function paidOrder(?User $user, array $attributes): Order
    {
        return $this->orderWith(array_merge([
            'user_id' => $user?->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'payment_status' => Payment::STATUS_PAID,
            'discount_amount' => 0,
            'delivery_fee' => 0,
        ], $attributes));
    }

    /** An accepted advance request, its first line partly paid with points. */
    private function acceptedRequest(User $customer, Food $dish, int $quantity, int $redeemed): Order
    {
        $subtotal = (float) $dish->price * $quantity;
        $points = (float) $dish->price * $redeemed;

        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'points_amount' => $points,
            'delivery_fee' => 0,
            'total_amount' => $subtotal - $points,
            'total_price' => $subtotal - $points,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'food_id' => $dish->id,
            'quantity' => $quantity,
            'redeemed_quantity' => $redeemed,
            'price' => $dish->price,
            'unit_price' => $dish->price,
            'subtotal' => $subtotal,
        ]);

        return $order;
    }

    // -----------------------------------------------------------------
    // Earning
    // -----------------------------------------------------------------

    public function test_a_completed_order_earns_one_percent_of_its_food_without_vat(): void
    {
        $customer = $this->customer();

        // ₱560 of food (₱500 before VAT); the discount and the delivery fee do not count.
        $order = $this->paidOrder($customer, [
            'order_type' => 'delivery',
            'subtotal' => 560,
            'discount_amount' => 51.43,
            'vat_exempt_amount' => 19.29,
            'delivery_fee' => 60,
            'total_amount' => 568.57,
            'total_price' => 568.57,
        ]);

        $order->update(['status' => OrderStatus::PREPARING]);
        $this->assertSame(0.0, $this->balance($customer), 'Nothing until it is completed.');

        $order->update(['status' => OrderStatus::DELIVERED]);

        $this->assertSame(5.0, $this->balance($customer));
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'reason' => LoyaltyTransaction::EARNED,
        ]);
        $this->assertSame(5.0, $order->fresh()->loyaltySummary()['points_earned']);
    }

    public function test_points_keep_centavos_and_are_earned_once(): void
    {
        $customer = $this->customer();
        $order = $this->paidOrder($customer, ['subtotal' => 160, 'total_amount' => 160, 'total_price' => 160]);

        $order->update(['status' => OrderStatus::COMPLETED]);
        app(LoyaltyPoints::class)->earn($order->fresh());

        // ₱160 / 1.12 = ₱142.857..., 1% of that.
        $this->assertSame(1.43, $this->balance($customer));
        $this->assertSame(1, LoyaltyTransaction::count());
    }

    public function test_guests_staff_and_cancelled_orders_earn_nothing(): void
    {
        $guestOrder = $this->paidOrder(null, ['subtotal' => 500, 'total_amount' => 500, 'total_price' => 500, 'guest_email' => 'guest@example.test']);
        $guestOrder->update(['status' => OrderStatus::COMPLETED]);

        $agent = $this->customer(0, User::ROLE_ADMIN);
        $staffOrder = $this->paidOrder($agent, ['subtotal' => 500, 'total_amount' => 500, 'total_price' => 500]);
        $staffOrder->update(['status' => OrderStatus::COMPLETED]);

        $customer = $this->customer();
        $cancelled = $this->paidOrder($customer, ['subtotal' => 500, 'total_amount' => 500, 'total_price' => 500]);
        $cancelled->update(['status' => OrderStatus::CANCELLED]);

        $this->assertSame(0, LoyaltyTransaction::count());
        $this->assertSame(0.0, $this->balance($agent));
        $this->assertSame(0.0, $this->balance($customer));
    }

    // -----------------------------------------------------------------
    // Paying with points
    // -----------------------------------------------------------------

    public function test_a_dish_is_paid_with_points_and_its_add_ons_with_money(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $rice = Addon::create(['addon_name' => 'Rice', 'addon_group' => 'Sides', 'addon_price' => 20]);
        $inasal->addons()->sync([$rice->id]);

        $line = $this->addToCart($customer, $inasal, 2, [['id' => $rice->id]]);

        // 2 × (₱160 + ₱20 rice) = ₱360; one Inasal paid with points, its rice with money.
        $quote = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 1]]]))
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('subtotal', 360)
            ->assertJsonPath('points_amount', 160)
            ->assertJsonPath('total', 200)
            ->assertJsonPath('items.0.redeemed_quantity', 1)
            ->assertJsonPath('items.0.points_price', 160)
            ->assertJsonPath('loyalty.balance', 200)
            ->assertJsonPath('loyalty.points_used', 160)
            // ₱200 left to pay for food, ₱178.57 before VAT.
            ->assertJsonPath('loyalty.points_to_earn', 1.79);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup([
                'redeem' => [['cart_item_id' => $line, 'quantity' => 1]],
                'expected_total' => $quote->json('total'),
            ]))
            ->assertCreated()
            ->assertJsonPath('order.points_amount', 160)
            ->assertJsonPath('order.items.0.redeemed_quantity', 1);

        $order = Order::findOrFail($response->json('order.id'));

        // Held as soon as it is placed, so no other checkout can spend them.
        $this->assertSame(40.0, $this->balance($customer));
        $this->assertSame(200.0, $this->gateway->latest()->amount);

        $this->payCheckout($customer, $order);
        $this->assertSame(OrderStatus::PLACED, $order->fresh()->status);

        $order->fresh()->update(['status' => OrderStatus::COMPLETED]);

        $this->assertSame(41.79, $this->balance($customer));
        $this->assertSame(['amount' => 160.0, 'points_used' => 160.0, 'points_earned' => 1.79], $order->fresh()->loyaltySummary());
    }

    public function test_points_that_do_not_cover_the_dish_stop_the_checkout(): void
    {
        $customer = $this->customer(100);
        $inasal = $this->dish('Chicken Inasal', 160);
        $line = $this->addToCart($customer, $inasal);
        $redeem = ['redeem' => [['cart_item_id' => $line, 'quantity' => 1]]];

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup($redeem))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'POINTS_NOT_ENOUGH')
            ->assertJsonPath('blockers.0.message', 'You have 100.00 points, and Chicken Inasal needs 160.00.')
            ->assertJsonPath('points_amount', 0)
            ->assertJsonPath('total', 160);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup($redeem))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'POINTS_NOT_ENOUGH');

        $this->assertSame(0, Order::count());
        $this->assertSame(100.0, $this->balance($customer));
    }

    public function test_a_redemption_must_name_units_in_the_order(): void
    {
        $customer = $this->customer(1000);
        $inasal = $this->dish('Chicken Inasal', 160);
        $line = $this->addToCart($customer, $inasal, 2);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 3]]]))
            ->assertJsonPath('blockers.0.code', 'LOYALTY_TOO_MANY');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup(['redeem' => [['cart_item_id' => $line + 999, 'quantity' => 1]]]))
            ->assertJsonPath('blockers.0.code', 'LOYALTY_ITEM_NOT_IN_ORDER');

        // Both units, when the balance covers them.
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 2]]]))
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('points_amount', 320)
            ->assertJsonPath('total', 0);
    }

    public function test_the_same_points_cannot_pay_for_two_checkouts(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $fries = $this->dish('Fries', 90);

        $first = $this->addToCart($customer, $inasal);
        $this->addToCart($customer, $fries);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup([
                'cart_item_ids' => [$first, $this->lineId($customer, $fries)],
                'redeem' => [['cart_item_id' => $first, 'quantity' => 1]],
            ]))
            ->assertCreated();

        $second = $this->addToCart($customer, $inasal);

        // A new request reads the account afresh, as a real one does.
        $this->actingAs($customer->fresh(), 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup(['redeem' => [['cart_item_id' => $second, 'quantity' => 1]]]))
            ->assertJsonPath('blockers.0.code', 'POINTS_NOT_ENOUGH');
    }

    public function test_the_discount_never_goes_on_a_dish_paid_with_points(): void
    {
        $customer = $this->customer(500);
        Discount::create([
            'user_id' => $customer->id,
            'discount_type' => Discount::TYPE_PWD,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_APPROVED,
            'verified_at' => now(),
        ]);

        $herb = $this->dish('Herb Chicken', 250, true);
        $spaghetti = $this->dish('Chicken Spaghetti', 180, true);
        $herbLine = $this->addToCart($customer, $herb);
        $this->addToCart($customer, $spaghetti);

        // Herb Chicken is paid with points, so the discount goes on the spaghetti.
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickup([
                'apply_discount' => true,
                'redeem' => [['cart_item_id' => $herbLine, 'quantity' => 1]],
            ]))
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('discount.item.food_name', 'Chicken Spaghetti')
            ->assertJsonPath('discount.amount', 51.43)
            ->assertJsonPath('total', 128.57);
    }

    public function test_an_order_paid_wholly_with_points_goes_to_the_store_without_a_payment_page(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $line = $this->addToCart($customer, $inasal);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 1]], 'expected_total' => 0]))
            ->assertCreated()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('payment', null)
            ->assertJsonPath('order.status', OrderStatus::PLACED)
            ->assertJsonPath('order.total', 0)
            ->assertJsonPath('order.payment.method', Payment::METHOD_POINTS);

        $order = Order::findOrFail($response->json('order.id'));

        $this->assertNull($this->gateway->latest(), 'No payment page.');
        $this->assertNotNull($order->order_number);
        $this->assertTrue($order->isPaid());
        $this->assertSame(40.0, $this->balance($customer));
        $this->assertSame(19, $inasal->fresh()->stock_quantity, 'Sold, as a paid order.');
        Event::assertDispatched(OrderBroadcast::class);

        // No money moved, so the day's money received does not count it.
        $manager = $this->customer(0, User::ROLE_SUPER_ADMIN);
        $order->update(['status' => OrderStatus::COMPLETED]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/reports/daily-sales')
            ->assertOk()
            ->assertJsonPath('fulfilled.paid_with_points', 160)
            ->assertJsonPath('fulfilled.total', 0)
            ->assertJsonPath('fulfilled.mismatched_orders', 0)
            ->assertJsonPath('money.received.orders', 0)
            ->assertJsonPath('money.by_method', []);
    }

    public function test_cancelling_gives_the_points_back(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $line = $this->addToCart($customer, $inasal);

        $order = Order::findOrFail($this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 1]]]))
            ->assertCreated()
            ->json('order.id'));

        $this->assertSame(40.0, $this->balance($customer));

        // The store has 15 minutes to answer before the customer may cancel.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(16));

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', false)
            ->assertJsonPath('message', 'Your order has been cancelled. Your points are back in your balance.');

        $this->assertSame(200.0, $this->balance($customer));
        $this->assertSame(0.0, $order->fresh()->loyaltySummary()['points_used']);
        // Paid with points only: no money to send back.
        $this->assertNull($order->fresh()->paidPayment->refund_amount);
        $this->assertNull($order->fresh()->paidPayment->refund_status);
    }

    public function test_an_unpaid_checkout_that_lapses_gives_the_points_back(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $fries = $this->dish('Fries', 90);
        $line = $this->addToCart($customer, $inasal);
        $this->addToCart($customer, $fries);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 1]]]))
            ->assertCreated();

        $this->assertSame(40.0, $this->balance($customer));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));
        $this->artisan('payments:expire-checkouts')->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, Order::firstOrFail()->status);
        $this->assertSame(200.0, $this->balance($customer));
    }

    public function test_a_late_payment_after_the_points_were_spent_elsewhere_is_owed_back(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $fries = $this->dish('Fries', 90);
        $line = $this->addToCart($customer, $inasal);
        $this->addToCart($customer, $fries);

        $late = Order::findOrFail($this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 1]]]))
            ->assertCreated()
            ->json('order.id'));

        // The page stays payable after the cancel, as a QR code already scanned.
        $session = (string) $late->payments()->latest('id')->value('provider_reference_id');
        $this->gateway->unreachable = true;
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));
        $this->artisan('payments:expire-checkouts')->assertSuccessful();
        $this->gateway->unreachable = false;
        $this->assertSame(200.0, $this->balance($customer));

        // The points go on another order meanwhile.
        $again = $this->addToCart($customer, $inasal);
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['redeem' => [['cart_item_id' => $again, 'quantity' => 1]]]))
            ->assertCreated();
        $this->assertSame(40.0, $this->balance($customer));

        // Then the first one's QR code is paid after all.
        $this->gateway->pay($session);
        $this->withHeader('x-callback-token', 'test-callback-token')
            ->postJson('/api/payments/xendit/webhook', ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => $session]])
            ->assertOk();

        $late->refresh();
        $this->assertSame(OrderStatus::CANCELLED, $late->status);
        $this->assertSame(PaymentSettlement::POINTS_GONE, $late->cancellation_reason);
        $this->assertSame('90.00', $late->paidPayment->refund_amount);
        $this->assertSame(40.0, $this->balance($customer), 'Not spent twice.');
    }

    // -----------------------------------------------------------------
    // Advance orders
    // -----------------------------------------------------------------

    public function test_an_advance_request_notes_the_points_and_takes_them_when_paid(): void
    {
        $customer = $this->customer(300);
        $inasal = $this->dish('Chicken Inasal', 160);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $inasal->id, 'quantity' => 2, 'mode' => 'advance'])
            ->assertCreated();

        $line = (int) $customer->carts()->where('cart_status', 'advance')->first()->items()->value('id');
        $tomorrow = CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/advance-orders', [
                'scheduled_for' => $tomorrow,
                'redeem' => [['cart_item_id' => $line, 'quantity' => 1]],
                'expected_total' => 160,
            ])
            ->assertCreated();

        $order = Order::findOrFail($response->json('order.id'));

        $this->assertSame('160.00', $order->points_amount);
        $this->assertSame(1, $order->items()->value('redeemed_quantity'));
        $this->assertSame(300.0, $this->balance($customer), 'Taken only when it is paid.');
    }

    public function test_an_advance_order_takes_its_points_at_the_price_of_the_day_it_is_paid(): void
    {
        $customer = $this->customer(300);
        $inasal = $this->dish('Chicken Inasal', 160);
        $order = $this->acceptedRequest($customer, $inasal, 2, 1);

        $inasal->update(['price' => 170]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true])
            ->assertOk()
            ->assertJsonPath('order.points_amount', 170)
            ->assertJsonPath('order.total', 170);

        $this->assertSame(130.0, $this->balance($customer));
        $this->assertSame(170.0, $this->gateway->latest()->amount);

        // Opening the payment again takes nothing more.
        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $this->assertSame(130.0, $this->balance($customer));

        $this->payCheckout($customer, $order);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_points_that_no_longer_cover_an_advance_dish_are_charged_after_telling_the_customer(): void
    {
        $customer = $this->customer(165);
        $inasal = $this->dish('Chicken Inasal', 160);
        $order = $this->acceptedRequest($customer, $inasal, 2, 1);

        $inasal->update(['price' => 170]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true, 'expected_total' => 160])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'POINTS_NOT_ENOUGH')
            ->assertJsonPath('message', 'Your points no longer cover Chicken Inasal, so it is charged this time. Your total is now ₱340.00. Please check it, then try again.')
            ->assertJsonPath('order.points_amount', 0)
            ->assertJsonPath('order.total', 340);

        $this->assertNull($this->gateway->latest(), 'Nothing opened before the customer saw it.');
        $this->assertSame(0, $order->items()->value('redeemed_quantity'));
        $this->assertSame(165.0, $this->balance($customer));

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['expected_total' => 340])
            ->assertOk();

        $this->assertSame(340.0, $this->gateway->latest()->amount);
    }

    public function test_an_advance_order_paid_wholly_with_points_is_confirmed_without_a_payment_page(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $order = $this->acceptedRequest($customer, $inasal, 1, 1);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay", ['reviewed' => true, 'expected_total' => 0])
            ->assertOk()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('payment', null)
            ->assertJsonPath('order.status', OrderStatus::CONFIRMED);

        $this->assertNull($this->gateway->latest());
        $this->assertSame(40.0, $this->balance($customer));
    }

    public function test_an_unpaid_advance_order_cancelled_gives_its_held_points_back(): void
    {
        $customer = $this->customer(300);
        $inasal = $this->dish('Chicken Inasal', 160);
        $order = $this->acceptedRequest($customer, $inasal, 2, 1);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $this->assertSame(140.0, $this->balance($customer));

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame(300.0, $this->balance($customer));
    }

    // -----------------------------------------------------------------
    // The customer's points, and the Store Manager's totals
    // -----------------------------------------------------------------

    public function test_the_customer_sees_the_balance_the_rates_and_the_history(): void
    {
        $customer = $this->customer(200);
        $inasal = $this->dish('Chicken Inasal', 160);
        $line = $this->addToCart($customer, $inasal);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/place', $this->pickup(['redeem' => [['cart_item_id' => $line, 'quantity' => 1]]]))
            ->assertCreated();

        $order = Order::firstOrFail();
        $order->update(['status' => OrderStatus::COMPLETED]);
        $customer = $customer->fresh();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/loyalty')
            ->assertOk()
            ->assertJsonPath('balance', 40)
            ->assertJsonPath('rates.earn_rate', 0.01)
            ->assertJsonPath('rates.peso_per_point', 1)
            ->assertJsonPath('history.0.reason', LoyaltyTransaction::REDEEMED)
            ->assertJsonPath('history.0.points_change', -160)
            ->assertJsonPath('history.0.label', 'Paid with points on '.$order->fresh()->title())
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($customer, 'sanctum')->getJson('/api/profile')->assertJsonPath('loyalty_points', 40);

        $this->actingAs($this->customer(0, User::ROLE_ADMIN), 'sanctum')->getJson('/api/loyalty')->assertForbidden();
    }

    public function test_the_store_manager_sees_issued_redeemed_and_outstanding_through_a_deletion(): void
    {
        $points = app(LoyaltyPoints::class);
        $julia = $this->customer();
        $marco = $this->customer();

        $points->earn(tap($this->paidOrder($julia, ['subtotal' => 1120, 'total_amount' => 1120, 'total_price' => 1120]))->update(['status' => OrderStatus::COMPLETED]));
        $points->earn(tap($this->paidOrder($marco, ['subtotal' => 560, 'total_amount' => 560, 'total_price' => 560]))->update(['status' => OrderStatus::COMPLETED]));

        $spend = $this->paidOrder($julia, ['subtotal' => 160, 'points_amount' => 4, 'total_amount' => 156, 'total_price' => 156]);
        $this->assertTrue($points->hold($spend));

        $manager = $this->customer(0, User::ROLE_SUPER_ADMIN);

        // 10 + 5 issued, 4 redeemed, 11 left in the wallets.
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/reports/analytics')
            ->assertOk()
            ->assertJsonPath('loyalty', ['issued' => 15, 'redeemed' => 4, 'outstanding' => 11]);

        $marco->delete();

        // His history stays without his name; his unused points leave Outstanding.
        $this->assertSame(1, LoyaltyTransaction::query()->whereNull('user_id')->count());
        $this->assertSame(['issued' => 15.0, 'redeemed' => 4.0, 'outstanding' => 6.0], $points->totals());

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/settings/loyalty')
            ->assertOk()
            ->assertJsonPath('points_per_peso', 0.01)
            ->assertJsonPath('peso_per_point', 1)
            ->assertJsonPath('active', true);
    }
}
