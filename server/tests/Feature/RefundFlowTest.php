<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Http\Middleware\ResolveGuestOrder;
use App\Jobs\SendRefund;
use App\Mail\GuestOrderNotice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\GatewayRefund;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentRefunds;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Refunds (decided 2026-11-23, BR-21d), against the in-memory gateway.
 *
 *   GCash    sent to Xendit at once; refunded when Xendit confirms it, read
 *            back from Xendit; a refusal becomes a refund by hand, untold
 *   QR Ph    a refund by hand from the start; a Store Agent ticks it
 *   told     account holders in the app, guests by email, when a refund is
 *            owed and when it is refunded
 */
class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-callback-token';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);
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
        return User::factory()->create(['role' => $role, 'account_status' => User::STATUS_ACTIVE]);
    }

    /** A placed immediate order, paid 20 minutes ago, so its customer may cancel it. */
    private function paidOrder(?User $owner, string $method = Payment::METHOD_GCASH, array $attributes = []): Order
    {
        $order = Order::create($attributes + [
            'user_id' => $owner?->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 440,
            'total_price' => 440,
            'total_amount' => 440,
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
            'guest_email' => $owner === null ? 'julia@example.test' : null,
        ]);

        $this->payFor($order, ['payment_method' => $method, 'paid_at' => now()->subMinutes(20)]);

        return $order->fresh();
    }

    private function refundWebhook(string $event, array $data)
    {
        return $this->withHeader('x-callback-token', self::TOKEN)
            ->postJson('/api/payments/xendit/webhook', ['event' => $event, 'data' => $data]);
    }

    private function noticesFor(User $user): array
    {
        return Notification::query()->where('user_id', $user->id)->orderBy('id')->pluck('body', 'title')->all();
    }

    // -----------------------------------------------------------------
    // GCash: through Xendit
    // -----------------------------------------------------------------

    public function test_a_gcash_refund_goes_to_xendit_when_a_customer_cancels(): void
    {
        $customer = $this->user();
        $order = $this->paidOrder($customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded', true)
            ->assertJsonPath('order.payment.refund.state', 'refunding')
            ->assertJsonPath('order.payment.refund.amount', 440);

        $payment = $order->paidPayment->fresh();

        $this->assertSame(Payment::REFUND_PENDING, $payment->refund_status);
        $this->assertSame('440.00', $payment->refund_amount);
        $this->assertCount(1, $this->gateway->refunds);

        $refund = $this->gateway->refunds[$payment->refund_reference_id];
        $this->assertSame($payment->idempotency_key, $refund->paymentId);
        $this->assertSame('cnc-refund-'.$payment->id, $refund->reference);

        $this->assertStringContainsString('₱440.00 is on its way back to your GCash.', implode(' ', $this->noticesFor($customer)));
    }

    public function test_xendits_confirmation_marks_it_refunded_and_tells_the_customer(): void
    {
        $customer = $this->user();
        $order = $this->paidOrder($customer);
        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();
        $payment = $order->paidPayment->fresh();

        $this->gateway->settleRefund($payment->refund_reference_id);
        $this->refundWebhook('refund.succeeded', [
            'refund_id' => $payment->refund_reference_id,
            'reference_id' => $payment->refundReference(),
            'status' => 'SUCCEEDED',
        ])->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::REFUND_REFUNDED, $payment->refund_status);
        $this->assertNotNull($payment->refunded_at);

        $this->assertSame(
            "Your ₱440.00 refund for order #{$order->id} has been sent back to your GCash.",
            $this->noticesFor($customer)['Refund sent'] ?? null
        );

        // Told once, however often Xendit repeats itself.
        $this->refundWebhook('refund.succeeded', ['refund_id' => $payment->refund_reference_id])->assertOk();
        $this->assertSame(1, Notification::query()->where('user_id', $customer->id)->where('title', 'Refund sent')->count());
    }

    public function test_a_refund_notification_is_not_believed_over_xendit(): void
    {
        $customer = $this->user();
        $order = $this->paidOrder($customer);
        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();
        $payment = $order->paidPayment->fresh();

        // Xendit still says PENDING, whatever the body claims.
        $this->refundWebhook('refund.succeeded', ['refund_id' => $payment->refund_reference_id, 'status' => 'SUCCEEDED'])->assertOk();
        $this->assertSame(Payment::REFUND_PENDING, $payment->fresh()->refund_status);

        // Nor is a refund made under another reference.
        $other = $this->gateway->refund($payment->idempotency_key, 440, 'someone-else', PaymentRefunds::REASON_CUSTOMER);
        $this->gateway->settleRefund($other->id);
        $this->refundWebhook('refund.succeeded', ['refund_id' => $other->id, 'reference_id' => $payment->refundReference()])->assertOk();
        $this->assertSame(Payment::REFUND_PENDING, $payment->fresh()->refund_status);

        $this->refundWebhook('refund.succeeded', ['refund_id' => $payment->refund_reference_id])
            ->assertOk();
        $this->withHeader('x-callback-token', 'wrong')
            ->postJson('/api/payments/xendit/webhook', ['event' => 'refund.succeeded', 'data' => []])
            ->assertUnauthorized();
    }

    public function test_a_refund_xendit_refuses_becomes_a_refund_by_hand_and_is_not_announced(): void
    {
        $customer = $this->user();
        $order = $this->paidOrder($customer);
        $this->gateway->refuseRefunds = true;

        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();

        $this->assertSame(Payment::REFUND_MANUAL, $order->paidPayment->fresh()->refund_status);
        $this->assertArrayNotHasKey('Refund sent', $this->noticesFor($customer));

        // One Xendit later reports failed goes the same way.
        $this->gateway->refuseRefunds = false;
        $second = $this->paidOrder($customer);
        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$second->id}/cancel")->assertOk();
        $payment = $second->paidPayment->fresh();
        $this->gateway->settleRefund($payment->refund_reference_id, GatewayRefund::FAILED);
        $this->refundWebhook('refund.failed', ['refund_id' => $payment->refund_reference_id])->assertOk();

        $this->assertSame(Payment::REFUND_MANUAL, $payment->fresh()->refund_status);
    }

    public function test_xendit_out_of_reach_leaves_it_to_refund_by_hand_without_failing_the_cancellation(): void
    {
        $customer = $this->user();
        $order = $this->paidOrder($customer);
        $this->gateway->unreachable = true;

        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(Payment::REFUND_MANUAL, $order->paidPayment->fresh()->refund_status);
    }

    public function test_a_retry_follows_the_refund_it_already_sent_rather_than_sending_another(): void
    {
        $customer = $this->user();
        $order = $this->paidOrder($customer);
        $payment = $order->paidPayment;

        // An earlier try reached Xendit, but its answer was lost.
        $payment->forceFill(['refund_amount' => 440, 'refund_status' => Payment::REFUND_PENDING])->save();
        $sent = $this->gateway->refund($payment->idempotency_key, 440, $payment->refundReference(), PaymentRefunds::REASON_CUSTOMER);

        (new SendRefund($payment->id, PaymentRefunds::REASON_CUSTOMER))->handle(app(PaymentRefunds::class));

        $this->assertCount(1, $this->gateway->refunds);
        $this->assertSame($sent->id, $payment->fresh()->refund_reference_id);
    }

    // -----------------------------------------------------------------
    // By hand: QR Ph, and what Xendit would not take
    // -----------------------------------------------------------------

    public function test_a_qrph_refund_is_by_hand_and_never_sent_to_xendit(): void
    {
        $customer = $this->user();
        $order = $this->paidOrder($customer, Payment::METHOD_QRPH);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/order/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('order.payment.refund.state', 'by_hand');

        $this->assertSame([], $this->gateway->refunds);
        $this->assertStringContainsString('Please contact the store to get your ₱440.00 back.', implode(' ', $this->noticesFor($customer)));
    }

    public function test_a_store_agent_ticks_a_refund_by_hand_and_the_customer_is_told(): void
    {
        $customer = $this->user();
        $agent = $this->user(User::ROLE_ADMIN);
        $order = $this->paidOrder($customer, Payment::METHOD_QRPH);
        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$order->id}/cancel")->assertOk();
        $payment = $order->paidPayment->fresh();

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/agent/payments/{$payment->id}/refunded", ['reference' => 'GC-REF-123'])
            ->assertOk()
            ->assertJsonPath('refund.state', 'refunded')
            ->assertJsonPath('refund.reference', 'GC-REF-123');

        $payment->refresh();
        $this->assertSame(Payment::REFUND_REFUNDED, $payment->refund_status);
        $this->assertNotNull($payment->refunded_at);
        $this->assertSame("The store has sent your ₱440.00 refund for order #{$order->id}.", $this->noticesFor($customer)['Refund sent'] ?? null);

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/agent/payments/{$payment->id}/refunded")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ALREADY_REFUNDED');
    }

    public function test_only_a_store_agent_ticks_and_only_a_refund_by_hand(): void
    {
        $customer = $this->user();
        $qrph = $this->paidOrder($customer, Payment::METHOD_QRPH);
        $gcash = $this->paidOrder($customer);
        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$qrph->id}/cancel")->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/order/{$gcash->id}/cancel")->assertOk();

        $byHand = $qrph->paidPayment->fresh();

        // The Store Manager reads Order Records but does not work it (decided 2026-11-23).
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->postJson("/api/agent/payments/{$byHand->id}/refunded")
            ->assertForbidden();
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/agent/payments/{$byHand->id}/refunded")
            ->assertForbidden();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/agent/payments/{$gcash->paidPayment->id}/refunded")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_A_REFUND_BY_HAND');

        $this->assertSame(Payment::REFUND_MANUAL, $byHand->fresh()->refund_status);
    }

    // -----------------------------------------------------------------
    // Guests are told by email
    // -----------------------------------------------------------------

    public function test_a_guest_is_emailed_the_cancellation_and_then_the_refund(): void
    {
        Mail::fake();

        $order = $this->paidOrder(null, Payment::METHOD_QRPH);
        $token = $order->mintGuestToken();

        $this->withHeader(ResolveGuestOrder::HEADER, $token)->postJson('/api/guest/order/cancel')->assertOk();

        Mail::assertSent(GuestOrderNotice::class, fn (GuestOrderNotice $mail) => $mail->hasTo('julia@example.test')
            && $mail->notice['subject'] === "Your order #{$order->id} was cancelled"
            && $mail->notice['body'] === "Your order #{$order->id} has been cancelled. Please contact the store to get your ₱440.00 back."
            && $mail->url === config('app.frontend_url').'/track#'.$token);

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/agent/payments/{$order->paidPayment->id}/refunded")
            ->assertOk();

        Mail::assertSent(GuestOrderNotice::class, fn (GuestOrderNotice $mail) => $mail->notice['heading'] === 'Refund sent'
            && $mail->notice['body'] === "The store has sent your ₱440.00 refund for order #{$order->id}.");
        Mail::assertSent(GuestOrderNotice::class, 2);
    }

    public function test_a_guest_is_emailed_when_the_store_cancels_their_order(): void
    {
        Mail::fake();

        $order = $this->paidOrder(null);
        $order->mintGuestToken();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'We ran out of chicken.', 'refund' => true])
            ->assertOk()
            ->assertJsonPath('refund.state', 'refunding');

        Mail::assertSent(GuestOrderNotice::class, fn (GuestOrderNotice $mail) => $mail->notice['body']
            === "The store cancelled your order #{$order->id}: We ran out of chicken. ₱440.00 is on its way back to your GCash.");
    }

    public function test_a_store_agent_choosing_no_refund_records_nothing_owed(): void
    {
        $order = $this->paidOrder($this->user());

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel", ['reason' => 'Never collected.', 'refund' => false])
            ->assertOk()
            ->assertJsonPath('refund.amount', 0)
            ->assertJsonPath('refund.state', 'none');

        $this->assertSame('0.00', $order->paidPayment->fresh()->refund_amount);
        $this->assertSame([], $this->gateway->refunds);
    }

    // -----------------------------------------------------------------
    // Order Records
    // -----------------------------------------------------------------

    public function test_records_show_the_payment_that_paid_and_an_extra_one_apart(): void
    {
        $order = $this->paidOrder($this->user());
        $order->forceFill(['status' => OrderStatus::COMPLETED])->save();
        $first = $order->paidPayment;
        $extra = $this->payFor($order, ['payment_method' => Payment::METHOD_QRPH, 'paid_at' => now()->subMinutes(5), 'refund_amount' => 440, 'refund_status' => Payment::REFUND_MANUAL]);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson('/api/agent/records/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.payment.id', $first->id)
            ->assertJsonPath('data.payment.method', Payment::METHOD_GCASH)
            ->assertJsonPath('data.payment.xendit_payment_id', $first->idempotency_key)
            ->assertJsonPath('data.payment.refund', null)
            ->assertJsonPath('data.payment.extra_payments.0.id', $extra->id)
            ->assertJsonPath('data.payment.extra_payments.0.refund.state', 'by_hand');

        $this->getJson('/api/agent/records?outcome=refunds')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.extra_refunds.0.state', 'by_hand')
            ->assertJsonPath('summary.refunds_to_do', 1)
            ->assertJsonPath('summary.refunds_owed', 440);
    }

    public function test_the_payer_is_read_from_xendit_and_never_stored(): void
    {
        $order = $this->paidOrder($this->user(), Payment::METHOD_QRPH);
        $payment = $order->paidPayment;
        $this->gateway->payers[$payment->idempotency_key] = [
            'name' => 'JULIA V***',
            'account' => '0917****567',
            'issuer' => 'GCash',
            'receipt' => '612345678901',
        ];
        $before = $payment->fresh()->getAttributes();

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson("/api/agent/payments/{$payment->id}/payer")
            ->assertOk()
            ->assertJsonPath('payer.name', 'JULIA V***')
            ->assertJsonPath('payer.issuer', 'GCash')
            ->assertJsonPath('payer.receipt', '612345678901');

        $this->assertSame($before, $payment->fresh()->getAttributes());

        $this->gateway->unreachable = true;
        $this->getJson("/api/agent/payments/{$payment->id}/payer")
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'PAYMENT_UNAVAILABLE');
    }
}
