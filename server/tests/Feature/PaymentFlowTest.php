<?php

namespace Tests\Feature;

use App\Events\MenuBroadcast;
use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Http\Controllers\CartController;
use App\Http\Middleware\ResolveGuestOrder;
use App\Models\CartItem;
use App\Models\Food;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockHold;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\CheckoutExpiry;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\GatewaySession;
use App\Services\Payments\PaymentGateway;
use App\Services\Verification\Channel;
use App\Support\NativeApp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Paying through Xendit's hosted checkout (FR-04, decided 2026-11-17), against
 * the in-memory gateway: checkout opens a payment page and leaves the order
 * awaiting payment; the payment places it; ten minutes unpaid cancels it; a
 * payment that lands after that revives it, or is owed back.
 */
class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-callback-token';

    private int $phoneSeq = 0;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class, MenuBroadcast::class]);
        config(['services.xendit.webhook_token' => self::TOKEN]);

        $this->gateway = app(PaymentGateway::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function user(string $role = User::ROLE_CUSTOMER): User
    {
        $phone = '+63917500'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

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

    private function food(int $stock = 10): Food
    {
        return Food::create([
            'food_name' => 'Herb Roasted Chicken',
            'description' => 'Whole.',
            'price' => 350,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => $stock,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 20,
        ]);
    }

    private function body(): array
    {
        return [
            'fulfilment_type' => 'pickup',
            'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
            'contact_email' => 'julia@example.test',
        ];
    }

    /** A customer with a filled cart checks out. Returns the response. */
    private function checkout(User $customer, Food $food, int $quantity = 2)
    {
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => $quantity])
            ->assertCreated();

        return $this->actingAs($customer, 'sanctum')->postJson('/api/order/place', $this->body());
    }

    private function sessionOf(Order $order): string
    {
        return (string) $order->payments()->latest('id')->value('provider_reference_id');
    }

    private function webhook(string $event, array $data, string $token = self::TOKEN)
    {
        return $this->withHeader('x-callback-token', $token)
            ->postJson('/api/payments/xendit/webhook', ['event' => $event, 'data' => $data]);
    }

    /** Xendit reports a completed session. */
    private function completed(Order $order)
    {
        return $this->webhook('payment_session.completed', [
            'payment_session_id' => $this->sessionOf($order),
            'status' => 'COMPLETED',
        ]);
    }

    private function staffSees(Order $order): bool
    {
        $ids = collect($this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->getJson('/api/orders/all?per_page=100')
            ->assertOk()
            ->json('orders'))->pluck('id');

        // Order Records lists closed orders; it must agree.
        if (! $ids->contains($order->id) && $order->fresh()->isTerminal()) {
            $records = collect($this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
                ->getJson('/api/agent/records?per_page=50')
                ->assertOk()
                ->json('data'))->pluck('id');

            $this->assertFalse($records->contains($order->id), 'All Orders and Order Records disagree.');
        }

        return $ids->contains($order->id);
    }

    // -----------------------------------------------------------------
    // Checkout
    // -----------------------------------------------------------------

    public function test_checkout_opens_a_payment_page_and_waits_for_payment(): void
    {
        $customer = $this->user();
        $food = $this->food();

        $response = $this->checkout($customer, $food)
            ->assertCreated()
            ->assertJsonPath('order.status', OrderStatus::AWAITING_PAYMENT)
            ->assertJsonPath('order.awaiting_payment', true)
            ->assertJsonPath('order.can_cancel', false);

        $order = Order::firstOrFail();
        $session = $this->gateway->latest();

        $this->assertSame($session->url, $response->json('payment.url'));
        $this->assertSame(700.0, $session->amount);
        $this->assertSame('cnc-order-'.$order->id.'-payment-'.$order->payments()->value('id'), $session->reference);
        $this->assertSame(Payment::STATUS_PENDING, $order->payments()->value('payment_status'));

        // Held, not sold; out of the cart; unheard of by the shop.
        $this->assertSame(10, $food->fresh()->stock_quantity);
        $this->assertSame(8, $food->fresh()->available_quantity);
        $this->assertSame(0, CartItem::count());
        $this->assertNull($order->queue_number);
        Event::assertNotDispatched(OrderBroadcast::class);
        $this->assertSame(0, Notification::count());
        $this->assertFalse($this->staffSees($order));

        // Not an order to the customer either, until it is paid (decided 2026-11-17).
        $this->actingAs($customer, 'sanctum')->getJson('/api/orders')->assertJsonCount(0, 'data')->assertJsonPath('counts.active', 0);
        $this->actingAs($customer, 'sanctum')->getJson("/api/orders/{$order->id}")->assertNotFound();
        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}/payment")
            ->assertOk()
            ->assertJsonPath('paid', false)
            ->assertJsonPath('order.awaiting_payment', true);
    }

    public function test_when_the_payment_page_cannot_open_nothing_is_kept(): void
    {
        $customer = $this->user();
        $food = $this->food();
        $this->gateway->unreachable = true;

        $this->checkout($customer, $food)
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'PAYMENT_UNAVAILABLE');

        $order = Order::firstOrFail();

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(Order::PAYMENT_EXPIRED, $order->payment_status);
        $this->assertSame(10, $food->fresh()->available_quantity);
        $this->assertSame(1, CartItem::count(), 'The cart is left for another try.');
        $this->assertFalse($this->staffSees($order));
    }

    public function test_an_order_waiting_for_payment_cannot_be_cancelled_by_the_customer(): void
    {
        $customer = $this->user();
        $this->checkout($customer, $this->food())->assertCreated();
        $order = Order::firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'AWAITING_PAYMENT');

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Payment
    // -----------------------------------------------------------------

    public function test_a_payment_places_the_order_once(): void
    {
        $customer = $this->user();
        $food = $this->food();
        $this->checkout($customer, $food)->assertCreated();
        $order = Order::firstOrFail();

        $this->gateway->pay($this->sessionOf($order), 'QRPH');

        $this->completed($order)->assertOk();
        $this->completed($order)->assertOk();
        $this->actingAs($customer, 'sanctum')->getJson("/api/orders/{$order->id}/payment")->assertOk();

        $order->refresh();
        $payment = $order->payments()->firstOrFail();

        $this->assertSame(OrderStatus::PLACED, $order->status);
        $this->assertTrue($order->isPaid());
        $this->assertSame(1, $order->queue_number);
        $this->assertSame(Payment::STATUS_PAID, $payment->payment_status);
        $this->assertSame('QRPH', $payment->payment_method);
        $this->assertNotNull($payment->paid_at);
        $this->assertStringStartsWith('py-fake-', $payment->idempotency_key);

        $this->assertSame(8, $food->fresh()->stock_quantity);
        $this->assertSame(StockHold::CONFIRMED, $order->stockHolds()->value('status'));

        // Announced once, for all three notifications of the same payment.
        $this->assertSame(1, Notification::query()->where('user_id', $customer->id)->count());
        Event::assertDispatchedTimes(OrderBroadcast::class, 1);
        $this->assertTrue($this->staffSees($order));
    }

    public function test_a_webhook_with_the_wrong_token_is_refused(): void
    {
        $this->checkout($this->user(), $this->food())->assertCreated();
        $order = Order::firstOrFail();
        $this->gateway->pay($this->sessionOf($order));

        $this->webhook('payment_session.completed', ['payment_session_id' => $this->sessionOf($order)], 'wrong')
            ->assertStatus(401);

        config(['services.xendit.webhook_token' => '']);
        $this->webhook('payment_session.completed', ['payment_session_id' => $this->sessionOf($order)], '')
            ->assertStatus(401);

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_a_webhook_is_not_believed_over_the_provider(): void
    {
        $this->checkout($this->user(), $this->food())->assertCreated();
        $order = Order::firstOrFail();

        // Claims completion; Xendit, asked, says the session is still open.
        $this->completed($order)->assertOk();

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->payments()->value('payment_status'));
    }

    public function test_an_unknown_notification_is_acknowledged_and_ignored(): void
    {
        $this->webhook('payment_session.completed', ['payment_session_id' => 'ps-not-ours', 'reference_id' => 'someone-else'])
            ->assertOk();

        $this->assertSame(0, Payment::count());
    }

    public function test_paying_again_returns_to_the_open_page(): void
    {
        $customer = $this->user();
        $this->checkout($customer, $this->food())->assertCreated();
        $order = Order::firstOrFail();
        $first = $this->gateway->latest()->url;

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertOk()
            ->assertJsonPath('payment.url', $first);

        $this->assertSame(1, Payment::count());
    }

    public function test_only_the_owner_can_pay_for_an_order(): void
    {
        $this->checkout($this->user(), $this->food())->assertCreated();
        $order = Order::firstOrFail();

        $this->actingAs($this->user(), 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertNotFound();
        $this->actingAs($this->user(), 'sanctum')->getJson("/api/orders/{$order->id}/payment")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // The 10-minute window (FR-04.5)
    // -----------------------------------------------------------------

    public function test_an_unpaid_checkout_is_cancelled_after_ten_minutes(): void
    {
        $customer = $this->user();
        $food = $this->food();
        $this->checkout($customer, $food)->assertCreated();
        $order = Order::firstOrFail();
        $session = $this->sessionOf($order);

        $this->travel(9)->minutes();
        $this->artisan('payments:expire-checkouts')->assertSuccessful();
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);

        $this->travel(1)->minutes();
        $this->artisan('payments:expire-checkouts')->assertSuccessful();

        $order->refresh();

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(Order::PAYMENT_EXPIRED, $order->payment_status);
        $this->assertSame(CheckoutExpiry::REASON, $order->cancellation_reason);
        $this->assertSame(0.0, (float) $order->refund_owed);
        $this->assertContains($session, $this->gateway->cancelled, 'The payment page is closed first.');
        $this->assertSame(Payment::STATUS_CLOSED, $order->payments()->value('payment_status'));
        $this->assertSame(10, $food->fresh()->available_quantity);

        // Nobody is told and nobody sees it: it never became an order.
        $this->assertSame(0, Notification::count());
        Event::assertNotDispatched(OrderBroadcast::class);
        $this->actingAs($customer, 'sanctum')->getJson('/api/orders')->assertJsonCount(0, 'data');
        $this->actingAs($customer, 'sanctum')->getJson("/api/orders/{$order->id}")->assertNotFound();
        $this->assertFalse($this->staffSees($order));
    }

    public function test_checking_on_an_order_past_its_window_closes_it_at_once(): void
    {
        $customer = $this->user();
        $this->checkout($customer, $this->food())->assertCreated();
        $order = Order::firstOrFail();

        $this->travel(11)->minutes();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}/payment")
            ->assertOk()
            ->assertJsonPath('paid', false)
            ->assertJsonPath('order.status', OrderStatus::CANCELLED);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_AWAITING_PAYMENT');
    }

    public function test_a_payment_at_the_last_moment_wins_over_the_expiry(): void
    {
        $customer = $this->user();
        $this->checkout($customer, $this->food())->assertCreated();
        $order = Order::firstOrFail();

        $this->gateway->pay($this->sessionOf($order));
        $this->travel(10)->minutes();
        $this->artisan('payments:expire-checkouts')->assertSuccessful();

        $this->assertSame(OrderStatus::PLACED, $order->fresh()->status);
        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_a_late_payment_revives_the_order_while_the_stock_is_there(): void
    {
        $customer = $this->user();
        $food = $this->food();
        $this->checkout($customer, $food)->assertCreated();
        $order = Order::firstOrFail();
        $session = $this->sessionOf($order);

        // The fake keeps the page payable after the cancel, as a QR code that
        // was scanned before it can be.
        $this->gateway->unreachable = true;
        $this->travel(10)->minutes();
        $this->artisan('payments:expire-checkouts')->assertSuccessful();
        $this->gateway->unreachable = false;
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);

        $this->gateway->pay($session);
        $this->webhook('payment_session.completed', ['payment_session_id' => $session])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::PLACED, $order->status);
        $this->assertTrue($order->isPaid());
        $this->assertNull($order->closed_at);
        $this->assertNull($order->cancellation_reason);
        $this->assertNotNull($order->queue_number);
        $this->assertSame(8, $food->fresh()->stock_quantity);
        $this->assertTrue($this->staffSees($order));
    }

    public function test_a_late_payment_is_owed_back_when_the_stock_has_gone(): void
    {
        $customer = $this->user();
        $food = $this->food(2);
        $this->checkout($customer, $food)->assertCreated();
        $order = Order::firstOrFail();
        $session = $this->sessionOf($order);

        $this->gateway->unreachable = true;
        $this->travel(10)->minutes();
        $this->artisan('payments:expire-checkouts')->assertSuccessful();
        $this->gateway->unreachable = false;

        // Someone else buys the last two meanwhile.
        $food->forceFill(['stock_quantity' => 0])->save();

        $this->gateway->pay($session);
        $this->webhook('payment_session.completed', ['payment_session_id' => $session])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertTrue($order->isPaid());
        $this->assertSame(700.0, (float) $order->refund_owed);
        $this->assertNull($order->queue_number);

        // Money is owed back, so the staff can see it now.
        $this->assertTrue($this->staffSees($order));
        $this->assertStringContainsString(
            'will be refunded',
            (string) Notification::query()->where('user_id', $customer->id)->latest('id')->value('message')
                .Notification::query()->where('user_id', $customer->id)->latest('id')->value('body')
        );
    }

    /**
     * Decided 2026-11-17: the same rule as advance orders. A discounted order
     * uses the day's discount from the moment it exists, so a second checkout
     * cannot take it while the first is being paid for.
     */
    public function test_a_checkout_uses_the_days_discount_paid_or_not(): void
    {
        $customer = $this->user();
        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 350,
            'discount_amount' => 70,
            'total_amount' => 280,
            'total_price' => 280,
        ]);

        $usage = app(\App\Services\Orders\DiscountUsage::class);

        $this->assertTrue($usage->usedToday($customer));

        $order->forceFill(['status' => OrderStatus::CANCELLED, 'payment_status' => Order::PAYMENT_EXPIRED])->save();
        $this->assertTrue($usage->usedToday($customer));
    }

    // -----------------------------------------------------------------
    // Guests and advance orders
    // -----------------------------------------------------------------

    public function test_a_guest_pays_and_checks_from_the_tracking_token(): void
    {
        $food = $this->food();
        $cart = $this->postJson('/api/guest/cart/items', ['food_id' => $food->id])->assertCreated()->json('guest_token');

        $response = $this->withHeader(CartController::GUEST_HEADER, $cart)
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated()
            ->assertJsonPath('order.status', OrderStatus::AWAITING_PAYMENT);

        $token = $response->json('tracking_token');
        $order = Order::firstOrFail();
        $this->assertSame($this->gateway->latest()->url, $response->json('payment.url'));
        $this->assertStringNotContainsString($token, (string) config('services.xendit.return_url'));

        $this->withHeader(ResolveGuestOrder::HEADER, $token)->getJson('/api/guest/order')->assertNotFound();

        $this->withHeader(ResolveGuestOrder::HEADER, $token)
            ->postJson('/api/guest/order/pay')
            ->assertOk()
            ->assertJsonPath('payment.url', $this->gateway->latest()->url);

        $this->gateway->pay($this->sessionOf($order));

        $this->withHeader(ResolveGuestOrder::HEADER, $token)
            ->getJson('/api/guest/order/payment')
            ->assertOk()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('order.status', OrderStatus::PLACED);
    }

    public function test_paying_for_an_accepted_advance_order_confirms_it(): void
    {
        $customer = $this->user();
        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 1200,
            'discount_amount' => 0,
            'total_amount' => 1200,
            'total_price' => 1200,
        ]);

        $url = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertOk()
            ->json('payment.url');

        $session = $this->gateway->latest();
        $this->assertSame($session->url, $url);
        $this->assertSame(1200.0, $session->amount);

        $this->gateway->pay($session->id);
        $this->webhook('payment_session.completed', ['payment_session_id' => $session->id])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::CONFIRMED, $order->status);
        $this->assertTrue($order->isPaid());
        $this->assertNull($order->queue_number, 'An advance order takes no queue number.');
    }

    public function test_an_expiring_advance_request_closes_its_payment_page(): void
    {
        $customer = $this->user();
        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 1200,
            'discount_amount' => 0,
            'total_amount' => 1200,
            'total_price' => 1200,
        ]);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $session = $this->gateway->latest()->id;

        $this->travel(Order::ADVANCE_PAYMENT_HOURS + 1)->hours();
        $this->artisan('advance:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertContains($session, $this->gateway->cancelled);
        $this->assertSame(GatewaySession::CANCELED, $this->gateway->sessions[$session]->status);
    }

    public function test_a_checkout_from_the_app_returns_to_the_app_and_one_from_the_web_does_not(): void
    {
        $food = $this->food();

        $this->checkout($this->user(), $food, 1)->assertCreated();
        $this->assertFalse($this->gateway->returnsToApp[$this->gateway->latest()->id]);

        $this->withHeader(NativeApp::HEADER, NativeApp::ANDROID);
        $this->checkout($this->user(), $food, 1)->assertCreated();
        $this->assertTrue($this->gateway->returnsToApp[$this->gateway->latest()->id]);
    }

    public function test_a_guest_checkout_from_the_app_returns_to_the_app(): void
    {
        $food = $this->food();
        $this->withHeader(NativeApp::HEADER, NativeApp::ANDROID);
        $cart = $this->postJson('/api/guest/cart/items', ['food_id' => $food->id])->assertCreated()->json('guest_token');

        $this->withHeader(CartController::GUEST_HEADER, $cart)->postJson('/api/guest/orders', $this->body())->assertCreated();

        $this->assertTrue($this->gateway->returnsToApp[$this->gateway->latest()->id]);
    }

    public function test_paying_for_an_advance_order_from_the_app_returns_to_the_app(): void
    {
        $customer = $this->user();
        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
            'subtotal' => 500,
            'discount_amount' => 0,
            'total_amount' => 500,
            'total_price' => 500,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->withHeader(NativeApp::HEADER, NativeApp::ANDROID)
            ->postJson("/api/orders/{$order->id}/pay")
            ->assertOk();

        $this->assertTrue($this->gateway->returnsToApp[$this->gateway->latest()->id]);
    }
}
