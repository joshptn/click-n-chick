<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BR-16f - payment is due 24 hours after acceptance, anchored to acceptance so
 * it can never expire before the request was answered.
 */
class AdvancePaymentDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Manila')->utc());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function user(string $role): User
    {
        $phone = '+63921000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Person',
            'role' => $role,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function advanceOrder(User $customer, array $overrides = []): Order
    {
        $food = Food::create([
            'food_name' => 'Chicken Inasal',
            'description' => 'Charcoal grilled.',
            'price' => 200,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 15,
        ]);

        $order = Order::create(array_merge([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::SUBMITTED,
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(10)->setTime(12, 0),
            'subtotal' => 400,
            'total_amount' => 400,
            'total_price' => 400,
            'payment_status' => 'unpaid',
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'food_id' => $food->id,
            'quantity' => 2,
            'price' => 200,
            'unit_price' => 200,
            'subtotal' => 400,
        ]);

        return $order->fresh();
    }

    public function test_accepting_stamps_when_it_happened(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);

        $order = $this->advanceOrder($customer);

        $this->assertNull($order->accepted_at);

        $this->actingAs($agent)->postJson("/api/agent/advance-requests/{$order->id}/accept")->assertOk();

        $fresh = $order->fresh();

        $this->assertNotNull($fresh->accepted_at);
        $this->assertSame(
            CarbonImmutable::now()->addHours(24)->toIso8601String(),
            $fresh->paymentDueAt()->toIso8601String()
        );
    }

    public function test_the_deadline_runs_from_acceptance_not_from_the_collection_date(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        // Accepted late, for a date that is still three weeks away.
        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::AWAITING_PAYMENT,
            'accepted_at' => CarbonImmutable::now(),
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(21)->setTime(12, 0),
        ]);

        $this->assertFalse($order->isPaymentOverdue());
        $this->assertTrue($order->isPaymentOverdue(CarbonImmutable::now()->addHours(25)));
    }

    public function test_an_unanswered_request_has_no_deadline(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $order = $this->advanceOrder($customer);

        $this->assertNull($order->paymentDueAt());
        $this->assertFalse($order->isPaymentOverdue());
    }

    public function test_an_immediate_order_has_no_deadline(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 200,
            'total_amount' => 200,
            'total_price' => 200,
            'payment_status' => 'unpaid',
        ]);

        $this->assertNull($order->accepted_at);
        $this->assertNull($order->paymentDueAt());
    }

    // -----------------------------------------------------------------
    // The sweep
    // -----------------------------------------------------------------

    public function test_an_unpaid_request_is_cancelled_after_twenty_four_hours(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::AWAITING_PAYMENT,
            'accepted_at' => CarbonImmutable::now()->subHours(25),
        ]);

        $this->artisan('advance:expire-unpaid')->assertSuccessful();

        $fresh = $order->fresh();

        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        $this->assertStringContainsString('Payment was not received', $fresh->cancellation_reason);
        $this->assertSame(0.0, (float) $fresh->refund_owed);
        $this->assertNotNull($fresh->closed_at);
    }

    public function test_a_request_inside_the_window_is_left_alone(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::AWAITING_PAYMENT,
            'accepted_at' => CarbonImmutable::now()->subHours(23),
        ]);

        $this->artisan('advance:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_a_paid_request_is_never_expired(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::CONFIRMED,
            'accepted_at' => CarbonImmutable::now()->subDays(9),
            'payment_status' => 'paid',
        ]);

        $this->artisan('advance:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_an_unanswered_request_is_never_expired(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $order = $this->advanceOrder($customer, [
            'created_at' => CarbonImmutable::now()->subDays(9),
        ]);

        $this->artisan('advance:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::SUBMITTED, $order->fresh()->status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $order = $this->advanceOrder($customer, [
            'status' => OrderStatus::AWAITING_PAYMENT,
            'accepted_at' => CarbonImmutable::now()->subHours(30),
        ]);

        $this->artisan('advance:expire-unpaid --dry-run')->assertSuccessful();

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->fresh()->status);
    }
}
