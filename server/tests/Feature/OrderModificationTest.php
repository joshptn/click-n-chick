<?php

namespace Tests\Feature;

use App\Events\OrderBroadcast;
use App\Http\Middleware\ResolveGuestOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Changing an order's contact name and mobile number (BR-21b, UC-ORD-008,
 * decided 2026-11-17).
 *
 * The only details that can change, by the customer or a guest from their
 * tracking link, until the order closes, on every kind of order. The order's
 * own copy changes, never the profile. A Store Agent changes nothing, and the
 * address, note and collection time are not changeable at all.
 */
class OrderModificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00', 'Asia/Manila')->utc());
        Event::fake([OrderBroadcast::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'first_name' => 'Julia',
            'last_name' => 'Veneese',
            'phone_number' => '+639171112222',
        ]);
    }

    private function order(
        ?User $owner,
        string $type = 'delivery',
        string $status = OrderStatus::PLACED,
        bool $advance = false,
        string $paymentStatus = 'paid',
    ): Order {
        $order = $this->orderWith([
            'user_id' => $owner?->id,
            'order_type' => $type,
            'status' => OrderStatus::PLACED,
            'subtotal' => 410,
            'delivery_fee' => $type === 'delivery' ? 55 : 0,
            'total_amount' => 465,
            'total_price' => 465,
            'full_address' => $type === 'delivery' ? 'Old Street, Apalit' : null,
            'latitude' => $type === 'delivery' ? 14.97 : null,
            'longitude' => $type === 'delivery' ? 120.76 : null,
            'delivery_note' => $type === 'delivery' ? 'Blue gate' : null,
            'pickup_at' => $type === 'pickup' ? CarbonImmutable::now('Asia/Manila')->setTime(14, 0) : null,
            'scheduled_for' => $advance ? CarbonImmutable::now()->addDays(3) : null,
            'payment_status' => $paymentStatus,
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171112222',
        ]);

        if ($status !== OrderStatus::PLACED) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->fresh();
    }

    public function test_a_customer_changes_the_contact_on_the_order_only(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_name' => 'Marco Veneese', 'contact_phone' => '+63 917 333 4444'])
            ->assertOk()
            ->assertJsonPath('order.contact.name', 'Marco Veneese')
            ->assertJsonPath('order.contact.phone', '09173334444');

        $this->assertSame('Marco Veneese', $order->fresh()->contact_name);
        $this->assertSame('09173334444', $order->fresh()->contact_phone);

        // The profile is untouched.
        $this->assertSame('Julia', $customer->fresh()->first_name);
        $this->assertSame('+639171112222', $customer->fresh()->phone_number);

        // The staff boards hear of it.
        Event::assertDispatched(OrderBroadcast::class);
    }

    public function test_either_field_can_change_alone(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_phone' => '09175556666'])
            ->assertOk();

        $this->assertSame('Julia Veneese', $order->fresh()->contact_name);
        $this->assertSame('09175556666', $order->fresh()->contact_phone);
    }

    public function test_it_stays_changeable_until_the_order_closes(): void
    {
        $customer = $this->customer();

        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::ON_THE_WAY] as $status) {
            $order = $this->order($customer, 'delivery', $status);

            $this->actingAs($customer, 'sanctum')
                ->patchJson("/api/orders/{$order->id}", ['contact_name' => 'Marco'])
                ->assertOk();
        }

        $advance = $this->order($customer, 'pickup', OrderStatus::SCHEDULED, advance: true);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$advance->id}", ['contact_phone' => '09175556666'])
            ->assertOk()
            ->assertJsonPath('order.editable.contact_phone', true);
    }

    public function test_a_closed_order_cannot_be_changed(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pickup', OrderStatus::COMPLETED);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_name' => 'Marco'])
            ->assertForbidden();

        $this->assertSame('Julia Veneese', $order->fresh()->contact_name);
    }

    public function test_the_address_note_and_collection_time_cannot_be_changed(): void
    {
        $customer = $this->customer();
        $delivery = $this->order($customer, 'delivery');
        $pickup = $this->order($customer, 'pickup');

        foreach ([
            [$delivery, ['latitude' => 14.98, 'longitude' => 120.77, 'full_address' => 'New Street']],
            [$delivery, ['delivery_note' => 'Red gate']],
            [$pickup, ['pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(15, 0)->toIso8601String()]],
            [$delivery, ['contact_name' => 'Marco', 'delivery_note' => 'Red gate']],
        ] as [$order, $body]) {
            $this->actingAs($customer, 'sanctum')
                ->patchJson("/api/orders/{$order->id}", $body)
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'NOT_CHANGEABLE');
        }

        $this->assertSame('Old Street, Apalit', $delivery->fresh()->full_address);
        $this->assertSame('Blue gate', $delivery->fresh()->delivery_note);
        $this->assertSame('Julia Veneese', $delivery->fresh()->contact_name);
    }

    public function test_the_contact_is_checked_like_at_checkout(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CONTACT_PHONE_INVALID');

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_name' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CONTACT_NAME_REQUIRED');

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOTHING_TO_CHANGE');
    }

    public function test_a_store_agent_cannot_change_a_customers_contact(): void
    {
        $order = $this->order($this->customer());

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_name' => 'Someone else'])
            ->assertForbidden();

        $this->assertSame('Julia Veneese', $order->fresh()->contact_name);
    }

    public function test_one_customer_cannot_change_anothers_order(): void
    {
        $order = $this->order($this->customer());

        $this->actingAs($this->customer(), 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_name' => 'Someone else'])
            ->assertForbidden();
    }

    /** Only a guest's order carries an email of its own (decided 2026-11-22). */
    public function test_a_customer_cannot_change_an_email_on_their_order(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_email' => 'other@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_CHANGEABLE');

        $this->assertNull($order->fresh()->guest_email);
    }

    public function test_a_guest_changes_their_contact_from_the_tracking_link(): void
    {
        $order = $this->order(null, 'pickup');
        $token = $order->mintGuestToken();

        $this->withHeader(ResolveGuestOrder::HEADER, $token)
            ->patchJson('/api/guest/order', ['contact_name' => 'Marco Guest', 'contact_phone' => '09178889999'])
            ->assertOk()
            ->assertJsonPath('order.contact.name', 'Marco Guest');

        $this->assertSame('09178889999', $order->fresh()->contact_phone);
    }

    public function test_an_unpaid_checkout_is_not_an_order_to_change(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'pickup', OrderStatus::AWAITING_PAYMENT, paymentStatus: 'unpaid');

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['contact_name' => 'Marco'])
            ->assertNotFound();
    }

    public function test_an_older_account_order_reads_its_contact_off_the_account(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);
        $order->forceFill(['contact_name' => null, 'contact_phone' => null])->save();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('order.contact.name', 'Julia Veneese')
            ->assertJsonPath('order.contact.phone', '+639171112222');
    }

    public function test_details_are_correct_is_hidden_for_now(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('order.can_confirm_details', false);
    }
}
