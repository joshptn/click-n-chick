<?php

namespace Tests\Feature;

use App\Events\MenuBroadcast;
use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Http\Controllers\CartController;
use App\Http\Middleware\ResolveGuestOrder;
use App\Jobs\SendPushNotification;
use App\Mail\GuestOrderNotice;
use App\Mail\GuestTrackingLinkMail;
use App\Models\CartItem;
use App\Models\Food;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PushToken;
use App\Models\StockHold;
use App\Models\User;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\CheckoutExpiry;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\GatewaySession;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentSettlement;
use App\Services\Verification\Channel;
use App\Support\NativeApp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
        $this->assertNull($order->order_number);
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
        $this->assertTrue($order->isUnpaidCheckout());
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
        $this->assertSame('001', $order->order_number);
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

    public function test_store_agents_are_told_of_a_new_paid_order_in_the_app_and_by_push(): void
    {
        // Decided 2026-11-26: Store Agents only, like the other order notices.
        Queue::fake([SendPushNotification::class]);
        $agent = $this->user(User::ROLE_ADMIN);
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        PushToken::create([
            'user_id' => $agent->id,
            'personal_access_token_id' => $agent->createToken('web')->accessToken->getKey(),
            'token' => 'counter-laptop',
            'platform' => PushToken::PLATFORM_WEB,
        ]);

        $customer = $this->user();
        $this->checkout($customer, $this->food())->assertCreated();
        $order = Order::firstOrFail();

        // Unpaid, the shop has not got it yet.
        $this->assertSame(0, Notification::query()->where('user_id', $agent->id)->count());

        $this->gateway->pay($this->sessionOf($order));
        $this->completed($order)->assertOk();
        $this->completed($order)->assertOk();

        $notices = Notification::query()->where('user_id', $agent->id)->get();

        $this->assertCount(1, $notices);
        $this->assertSame('New order', $notices[0]->title);
        $this->assertSame('order', $notices[0]->notification_type);
        $this->assertSame($order->id, $notices[0]->order_id);
        $this->assertSame(
            "Order 001 from Julia Veneese - 2 item(s), ₱700.00, pickup. It is waiting under New on Today's Orders.",
            $notices[0]->body
        );
        $this->assertSame(0, Notification::query()->where('user_id', $manager->id)->count());

        Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job) => $job->userId === $agent->id
            && $job->title === 'New order'
            && $job->data['order_id'] === $order->id
            && $job->data['role'] === User::ROLE_ADMIN);
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
        $this->assertTrue($order->isUnpaidCheckout());
        $this->assertSame(CheckoutExpiry::REASON, $order->cancellation_reason);
        $this->assertFalse($order->payments()->refunds()->exists());
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
        $this->assertNotNull($order->order_number);
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
        $this->assertSame(700.0, (float) $order->paidPayment->refund_amount);
        $this->assertNull($order->order_number);

        // Money is owed back, so the staff can see it now.
        $this->assertTrue($this->staffSees($order));
        $this->assertStringContainsString(
            'Payment received, but your order was cancelled. Unfortunately, the items are no longer available.',
            (string) Notification::query()->where('user_id', $customer->id)->latest('id')->value('message')
                .Notification::query()->where('user_id', $customer->id)->latest('id')->value('body')
        );

        // GCash goes back through Xendit at once (decided 2026-11-23).
        $this->assertSame(Payment::REFUND_PENDING, $order->paidPayment->refund_status);
        $this->assertCount(1, $this->gateway->refunds);

        // The return screen checks the order as well as the payment.
        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}/payment")
            ->assertOk()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('order.status', OrderStatus::CANCELLED)
            ->assertJsonPath('order.payment_notice.kind', 'cancelled')
            ->assertJsonPath('order.payment_notice.title', 'Payment received, but your order was cancelled.')
            ->assertJsonPath('order.payment_notice.body', 'Unfortunately, the items are no longer available. We received your ₱700.00 payment, and a full refund of ₱700.00 is on its way back to your GCash. You can view your order details for updates.')
            ->assertJsonPath('order.payment.refund.state', 'refunding');
    }

    public function test_a_qrph_payment_that_cannot_place_the_order_is_refunded_by_hand(): void
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
        $food->forceFill(['stock_quantity' => 0])->save();

        $this->gateway->pay($session, 'QRPH');
        $this->webhook('payment_session.completed', ['payment_session_id' => $session])->assertOk();

        $payment = $order->fresh()->paidPayment;
        $this->assertSame(Payment::REFUND_MANUAL, $payment->refund_status);
        $this->assertSame([], $this->gateway->refunds, 'Xendit cannot refund QR Ph.');

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}/payment")
            ->assertJsonPath('order.payment_notice.body', 'Unfortunately, the items are no longer available. We received your ₱700.00 payment, and please contact the store to get your ₱700.00 back. You can view your order details for updates.');
    }

    /** Decided 2026-11-23: the second payment is refunded in full; the order goes ahead. */
    public function test_an_order_paid_twice_refunds_the_extra_payment(): void
    {
        $customer = $this->user();
        $this->checkout($customer, $this->food())->assertCreated();
        $order = Order::firstOrFail();
        $first = $this->sessionOf($order);

        // A second page was opened for the same order, and both were paid.
        $extra = Payment::create(['order_id' => $order->id, 'user_id' => $customer->id, 'payment_status' => Payment::STATUS_PENDING]);
        $second = $this->gateway->createSession($extra->gatewayReference(), 700, 'second', CarbonImmutable::now()->addMinutes(10));
        $extra->forceFill(['provider_reference_id' => $second->id])->save();

        $this->gateway->pay($first);
        $this->webhook('payment_session.completed', ['payment_session_id' => $first])->assertOk();
        $this->gateway->pay($second->id);
        $this->webhook('payment_session.completed', ['payment_session_id' => $second->id])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::PLACED, $order->status);
        $this->assertNull($order->paidPayment->refund_amount, 'The payment that paid for it is not refunded.');

        $extra->refresh();
        $this->assertTrue($extra->isVerified());
        $this->assertSame('700.00', $extra->refund_amount);
        $this->assertSame(Payment::REFUND_PENDING, $extra->refund_status);
        $this->assertSame($extra->idempotency_key, $this->gateway->refunds[$extra->refund_reference_id]->paymentId);

        $notice = Notification::query()->where('user_id', $customer->id)->where('title', 'You paid twice')->first();
        $this->assertNotNull($notice);
        $this->assertSame('You paid twice for order 001. The extra ₱700.00 is on its way back to your GCash.', $notice->body);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}/payment")
            ->assertJsonPath('order.status', OrderStatus::PLACED)
            ->assertJsonPath('order.payment_notice.kind', 'extra')
            ->assertJsonPath('order.payment_notice.body', 'You paid twice. The extra ₱700.00 is being refunded.');
    }

    /**
     * Decided 2026-11-17: the same rule as advance orders. A discounted order
     * uses the day's discount from the moment it exists, so a second checkout
     * cannot take it while the first is being paid for.
     */
    public function test_a_checkout_uses_the_days_discount_paid_or_not(): void
    {
        $customer = $this->user();
        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
            'subtotal' => 350,
            'discount_amount' => 70,
            'total_amount' => 280,
            'total_price' => 280,
        ]);

        $usage = app(\App\Services\Orders\DiscountUsage::class);

        $this->assertTrue($usage->usedToday($customer));

        $order->forceFill(['status' => OrderStatus::CANCELLED, 'cancelled_by' => null])->save();
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

    /** @return array{0: Order, 1: string} a guest's order waiting for payment, and its token */
    private function guestCheckout(Food $food): array
    {
        $cart = $this->postJson('/api/guest/cart/items', ['food_id' => $food->id, 'quantity' => 2])
            ->assertCreated()
            ->json('guest_token');

        $token = $this->withHeader(CartController::GUEST_HEADER, $cart)
            ->postJson('/api/guest/orders', $this->body())
            ->assertCreated()
            ->json('tracking_token');

        return [Order::query()->latest('id')->firstOrFail(), $token];
    }

    /** FR-02.13a, decided 2026-11-22: once, when the payment lands, and never before. */
    public function test_a_guests_tracking_link_is_emailed_once_when_the_payment_lands(): void
    {
        Mail::fake();

        [$order, $token] = $this->guestCheckout($this->food());

        $this->assertSame(Order::hashGuestToken($token), $order->guest_token_hash);
        Mail::assertNothingSent();

        $this->gateway->pay($this->sessionOf($order));

        // The webhook twice and the guest's own check: one email.
        $this->completed($order)->assertOk();
        $this->completed($order)->assertOk();
        $this->withHeader(ResolveGuestOrder::HEADER, $token)->getJson('/api/guest/order/payment')->assertOk();

        Mail::assertSent(GuestTrackingLinkMail::class, 1);
        Mail::assertSent(GuestTrackingLinkMail::class, function (GuestTrackingLinkMail $mail) use ($token) {
            return $mail->hasTo('julia@example.test')
                && $mail->url === config('app.frontend_url').'/track#'.$token
                && $mail->order->title() === 'Order 001';
        });
    }

    public function test_the_email_names_the_order_and_its_date_and_carries_the_link(): void
    {
        [$order, $token] = $this->guestCheckout($this->food());
        $order->forceFill(['order_number' => '005'])->save();

        $mail = new GuestTrackingLinkMail($order->fresh(), 'https://shop.example.test/track#'.$token);

        $mail->assertHasSubject('Track your order 005 – BES House of Chicken');
        $mail->assertSeeInHtml('Order 005');
        $mail->assertSeeInHtml('August 24, 2026');
        $mail->assertSeeInHtml('https://shop.example.test/track#'.$token, false);
        $mail->assertSeeInHtml('Enjoy your food!');
    }

    public function test_a_customers_order_sends_no_tracking_link(): void
    {
        Mail::fake();

        $customer = $this->user();
        $this->checkout($customer, $this->food())->assertCreated();
        $order = Order::firstOrFail();

        $this->gateway->pay($this->sessionOf($order));
        $this->completed($order)->assertOk();

        Mail::assertNotSent(GuestTrackingLinkMail::class);
    }

    /** Decided 2026-11-23: the guest is told by email, with the tracking link. */
    public function test_a_guest_whose_payment_cannot_place_the_order_is_told_by_email(): void
    {
        Mail::fake();

        $food = $this->food(2);
        [$order, $token] = $this->guestCheckout($food);
        $session = $this->sessionOf($order);

        $this->gateway->unreachable = true;
        $this->travel(10)->minutes();
        $this->artisan('payments:expire-checkouts')->assertSuccessful();
        $this->gateway->unreachable = false;

        $food->forceFill(['stock_quantity' => 0])->save();

        $this->gateway->pay($session);
        $this->webhook('payment_session.completed', ['payment_session_id' => $session])->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        Mail::assertNotSent(GuestTrackingLinkMail::class);
        Mail::assertSent(GuestOrderNotice::class, 1);
        Mail::assertSent(GuestOrderNotice::class, function (GuestOrderNotice $mail) use ($token) {
            return $mail->hasTo('julia@example.test')
                && $mail->url === config('app.frontend_url').'/track#'.$token
                && $mail->notice['subject'] === 'Payment received, but your order was cancelled'
                && $mail->notice['heading'] === 'Payment received, but your order was cancelled.'
                && str_starts_with($mail->notice['body'], 'Unfortunately, the items are no longer available. We received your ₱700.00 payment');
        });
    }

    /** Decided 2026-11-23: cancelling closes the page; a payment that still lands is refunded. */
    public function test_a_payment_after_the_store_cancelled_an_advance_order_is_refunded(): void
    {
        $customer = $this->user();
        $agent = $this->user(User::ROLE_ADMIN);
        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'advance_order_number' => '002',
            'status' => OrderStatus::AWAITING_PAYMENT,
            'subtotal' => 400,
            'discount_amount' => 0,
            'total_amount' => 400,
            'total_price' => 400,
        ]);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $session = $this->gateway->latest()->id;

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'The oven broke.'])
            ->assertOk();

        $this->assertContains($session, $this->gateway->cancelled, 'The payment page is closed first.');

        // A QR code scanned before the page closed still pays.
        $this->gateway->pay($session);
        $this->webhook('payment_session.completed', ['payment_session_id' => $session])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(PaymentSettlement::CLOSED, $order->cancellation_reason);
        $this->assertSame('400.00', $order->paidPayment->refund_amount);
        $this->assertSame(
            'Unfortunately, it arrived after the order had closed. We received your ₱400.00 payment, and a full refund of ₱400.00 is on its way back to your GCash. You can view your order details for updates.',
            app(OrderNotice::class)->paymentNotice($order)['body']
        );
    }

    /** Decided 2026-11-23: one the customer cancelled themselves goes ahead after all. */
    public function test_a_late_payment_revives_an_advance_order_the_customer_cancelled(): void
    {
        $customer = $this->user();
        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'advance_order_number' => '003',
            'status' => OrderStatus::AWAITING_PAYMENT,
            'subtotal' => 400,
            'discount_amount' => 0,
            'total_amount' => 400,
            'total_price' => 400,
        ]);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $session = $this->gateway->latest()->id;

        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();
        $this->assertContains($session, $this->gateway->cancelled, 'The payment page is closed first.');
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);

        $this->gateway->pay($session);
        $this->webhook('payment_session.completed', ['payment_session_id' => $session])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::CONFIRMED, $order->status);
        $this->assertNull($order->cancelled_by);
        $this->assertTrue($order->isPaid());
        $this->assertFalse($order->payments()->refunds()->exists());
    }

    public function test_paying_for_an_accepted_advance_order_confirms_it(): void
    {
        $customer = $this->user();
        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
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
        $this->assertNull($order->order_number, 'An advance order takes no immediate number.');
    }

    /** An accepted advance order collected at $collectAt, paid through the gateway. */
    private function payAdvance(CarbonImmutable $collectAt): Order
    {
        $customer = $this->user();
        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => $collectAt,
            'accepted_at' => now(),
            'advance_order_number' => '004',
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
            'subtotal' => 400,
            'discount_amount' => 0,
            'total_amount' => 400,
            'total_price' => 400,
        ]);

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/pay")->assertOk();
        $session = $this->gateway->latest()->id;
        $this->gateway->pay($session);
        $this->webhook('payment_session.completed', ['payment_session_id' => $session])->assertOk();

        return $order->fresh();
    }

    public function test_store_agents_are_told_a_paid_advance_order_waits_to_be_scheduled(): void
    {
        // Decided 2026-11-20: Store Agents only, not the Store Manager.
        $agent = $this->user(User::ROLE_ADMIN);
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $order = $this->payAdvance(CarbonImmutable::now()->addDays(3));

        $this->assertSame(OrderStatus::CONFIRMED, $order->status);

        $notice = Notification::where('user_id', $agent->id)->where('title', 'Advance order paid')->first();
        $this->assertNotNull($notice);
        $this->assertSame('advance_order', $notice->notification_type);
        $this->assertSame($order->id, $notice->order_id);
        $this->assertStringContainsString('Order A-004 was paid for', $notice->body);
        $this->assertStringContainsString('Schedule it from To Confirm.', $notice->body);

        $this->assertSame(0, Notification::where('user_id', $manager->id)->count());
    }

    public function test_a_paid_advance_order_for_today_is_to_be_started(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);

        $this->payAdvance(CarbonImmutable::now()->addHours(3));

        $body = Notification::where('user_id', $agent->id)->where('title', 'Advance order paid')->value('body');
        $this->assertStringContainsString('was paid and is for today', $body);
        $this->assertStringContainsString('Start preparing it from To Confirm.', $body);
    }

    public function test_an_expiring_advance_request_closes_its_payment_page(): void
    {
        $customer = $this->user();
        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
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
        $order = $this->orderWith([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'scheduled_for' => now()->addDays(3),
            'accepted_at' => now(),
            'status' => OrderStatus::AWAITING_PAYMENT,
            'payment_status' => 'unpaid',
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
