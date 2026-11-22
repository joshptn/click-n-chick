<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Http\Middleware\ResolveGuestOrder;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Guest order tracking (UC-GUEST-006, UC-ORD-007/010).
 *
 * Three properties carry the weight. Possession of the token is the whole of the
 * authorization, so the fence around what a token may resolve to has to hold. The
 * access window is decided by one predicate, so the HTTP surface and broadcasting
 * cannot disagree about when a link has died. And the broadcast payload is public
 * to anyone holding the channel name, so it must carry nothing personal.
 */
class GuestTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila')->utc());

        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: Order, 1: string} the order and the one copy of its token */
    private function guestOrder(array $overrides = []): array
    {
        $order = Order::create(array_merge([
            'user_id' => null,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 350,
            'total_price' => 350,
            'total_amount' => 350,
            'payment_status' => 'paid',
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
            'guest_email' => 'julia@example.test',
            'full_address' => 'ADD Street, Apalit',
            'latitude' => 14.97,
            'longitude' => 120.76,
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'food_id' => Food::create([
                'food_name' => 'Herb Roasted Chicken',
                'description' => 'Whole.',
                'price' => 350,
                'thumbnail' => 'https://example.test/chicken.jpg',
                'stock_quantity' => 10,
                'is_available' => true,
                'is_best_seller' => false,
                'prep_time' => 20,
            ])->id,
            'quantity' => 1,
            'price' => 350,
            'unit_price' => 350,
            'subtotal' => 350,
        ]);

        return [$order, $order->mintGuestToken()];
    }

    private function holdingToken(string $token): static
    {
        return $this->withHeader(ResolveGuestOrder::HEADER, $token);
    }

    // -----------------------------------------------------------------
    // Reading the order
    // -----------------------------------------------------------------

    public function test_a_token_opens_the_same_tracker_an_account_would_see(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.status', OrderStatus::PLACED)
            ->assertJsonPath('order.contact.name', 'Julia Veneese')
            ->assertJsonPath('order.contact.phone', '09171234567')
            ->assertJsonPath('channel', $order->guestChannel())
            // The shared resource, so the step chain comes along unchanged.
            ->assertJsonCount(count($order->statusChain()), 'order.steps');
    }

    public function test_a_wrong_token_is_not_told_whether_the_order_exists(): void
    {
        $this->guestOrder();

        $this->holdingToken(str_repeat('a', 64))
            ->getJson('/api/guest/order')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_no_token_at_all_is_refused(): void
    {
        $this->guestOrder();

        $this->getJson('/api/guest/order')->assertNotFound();
    }

    public function test_one_guests_token_cannot_open_anothers_order(): void
    {
        [$first] = $this->guestOrder();
        [, $secondToken] = $this->guestOrder();

        $this->holdingToken($secondToken)
            ->getJson('/api/guest/order')
            ->assertOk()
            ->assertJsonPath('order.id', fn ($id) => $id !== $first->id);
    }

    /** The fence: a token must never reach an order that belongs to an account. */
    public function test_a_token_cannot_resolve_to_an_account_order(): void
    {
        $user = User::factory()->create();

        [$order, $token] = $this->guestOrder(['user_id' => $user->id]);

        $this->assertNotNull($order->guest_token_hash);

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // The access window, and that one predicate governs it
    // -----------------------------------------------------------------

    public function test_a_link_works_until_seven_days_after_placement(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->travelTo(CarbonImmutable::now()->addDays(7)->subMinute());

        $this->holdingToken($token)->getJson('/api/guest/order')->assertOk();

        $this->assertTrue($order->fresh()->guestAccessIsLive());
    }

    public function test_an_order_still_open_after_seven_days_says_the_link_expired(): void
    {
        [, $token] = $this->guestOrder();

        $this->travelTo(CarbonImmutable::now()->addDays(7)->addMinute());

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'TRACKING_LINK_EXPIRED')
            ->assertJsonPath('message', 'This tracking link has expired. Please contact the store.');
    }

    public function test_a_completed_order_says_there_is_nothing_to_track(): void
    {
        [, $collected] = $this->guestOrder(['status' => OrderStatus::COMPLETED, 'closed_at' => CarbonImmutable::now()]);
        [, $delivered] = $this->guestOrder([
            'order_type' => 'delivery',
            'status' => OrderStatus::DELIVERED,
            'closed_at' => CarbonImmutable::now(),
        ]);

        $this->travelTo(CarbonImmutable::now()->addDays(8));

        foreach ([$collected, $delivered] as $token) {
            $this->holdingToken($token)
                ->getJson('/api/guest/order')
                ->assertStatus(410)
                ->assertJsonPath('error_code', 'ORDER_COMPLETED')
                ->assertJsonPath('message', 'The order is completed, nothing to track here.');
        }
    }

    public function test_a_cancelled_order_says_it_was_cancelled(): void
    {
        [, $token] = $this->guestOrder([
            'status' => OrderStatus::CANCELLED,
            'closed_at' => CarbonImmutable::now(),
        ]);

        $this->travelTo(CarbonImmutable::now()->addDays(8));

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'ORDER_CANCELLED')
            ->assertJsonPath('message', 'This order was cancelled. Nothing to track here.');
    }

    /** Decided 2026-11-22: counted from placement, so closing late adds nothing. */
    public function test_closing_an_order_does_not_extend_its_link(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->travelTo(CarbonImmutable::now()->addDays(6));
        $order->forceFill(['status' => OrderStatus::COMPLETED, 'closed_at' => CarbonImmutable::now()])->save();

        $this->travelTo(CarbonImmutable::now()->addDays(2));

        $this->holdingToken($token)->getJson('/api/guest/order')->assertStatus(410);
    }

    /** It was never an order, so it stays "not found" after the link lapses too. */
    public function test_an_expired_unpaid_checkout_is_still_not_found(): void
    {
        [, $token] = $this->guestOrder([
            'status' => OrderStatus::CANCELLED,
            'payment_status' => Order::PAYMENT_EXPIRED,
            'closed_at' => CarbonImmutable::now(),
        ]);

        $this->travelTo(CarbonImmutable::now()->addDays(8));

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_the_window_comes_from_configuration(): void
    {
        config()->set('store.guest.track_days', 1);

        [, $token] = $this->guestOrder();

        $this->travelTo(CarbonImmutable::now()->addDays(2));

        $this->holdingToken($token)->getJson('/api/guest/order')->assertStatus(410);
    }

    /** The point of sharing the predicate: both surfaces close at the same moment. */
    public function test_the_channel_disappears_exactly_when_the_link_expires(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->assertContains(
            $order->guestChannel(),
            $this->channelNames($order),
            'while the link works, the guest channel is broadcast on'
        );

        $this->travelTo(CarbonImmutable::now()->addDays(8));

        $this->holdingToken($token)->getJson('/api/guest/order')->assertStatus(410);

        $this->assertNotContains(
            $order->fresh()->guestChannel(),
            $this->channelNames($order->fresh()),
            'once the link is refused over HTTP, nothing is broadcast to it either'
        );
    }

    // -----------------------------------------------------------------
    // Broadcasting
    // -----------------------------------------------------------------

    /** @return array<int, string> */
    private function channelNames(Order $order): array
    {
        return array_map(
            fn ($channel) => (string) $channel->name,
            (new OrderBroadcast($order, 'update'))->broadcastOn()
        );
    }

    public function test_a_guest_order_is_broadcast_on_the_derived_public_channel(): void
    {
        [$order] = $this->guestOrder();

        $names = $this->channelNames($order);

        $this->assertContains('private-orders.'.$order->id, $names);
        $this->assertContains('private-admin.orders', $names);
        $this->assertContains($order->guestChannel(), $names);
    }

    public function test_an_account_order_is_broadcast_on_the_private_channels_only(): void
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'total_price' => 350,
        ]);

        $this->assertSame(
            ['private-orders.'.$order->id, 'private-admin.orders'],
            $this->channelNames($order)
        );
    }

    public function test_the_channel_name_is_derived_from_the_stored_verifier(): void
    {
        [$order] = $this->guestOrder();

        $this->assertSame(
            hash('sha256', 'channel|'.$order->guest_token_hash),
            $order->guestChannel()
        );

        // Not the verifier itself, so a leaked channel name cannot be used as a
        // database lookup key either.
        $this->assertNotSame($order->guest_token_hash, $order->guestChannel());
    }

    // -----------------------------------------------------------------
    // The token is derived from the order (decided 2026-11-22)
    // -----------------------------------------------------------------

    public function test_the_same_order_always_gets_the_same_token_and_channel(): void
    {
        [$order, $token] = $this->guestOrder();

        $channel = $order->guestChannel();

        $this->assertSame($token, $order->fresh()->mintGuestToken());
        $this->assertSame($channel, $order->fresh()->guestChannel());
    }

    public function test_the_token_can_be_rebuilt_from_the_order(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->assertSame($token, $order->fresh()->guestToken());
    }

    public function test_two_orders_never_share_a_token(): void
    {
        [, $first] = $this->guestOrder();
        [, $second] = $this->guestOrder();

        $this->assertNotSame($first, $second);
    }

    public function test_the_token_depends_on_the_key(): void
    {
        [$order, $token] = $this->guestOrder();

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('app.previous_keys', []);

        $this->assertNull($order->fresh()->guestToken(), 'no key reproduces it');
        $this->assertNotSame($token, Order::query()->find($order->id)->mintGuestToken());
    }

    public function test_a_previous_key_still_rebuilds_the_token_after_rotation(): void
    {
        [$order, $token] = $this->guestOrder();

        config()->set('app.previous_keys', [config('app.key')]);
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $this->assertSame($token, $order->fresh()->guestToken());

        // And the link itself keeps working: lookup is by the stored hash.
        $this->holdingToken($token)->getJson('/api/guest/order')->assertOk();
    }

    /** A link handed out before tokens were derived keeps opening its order. */
    public function test_a_random_token_from_before_still_opens_its_order(): void
    {
        [$order] = $this->guestOrder();

        $old = bin2hex(random_bytes(32));
        $order->forceFill(['guest_token_hash' => Order::hashGuestToken($old)])->save();

        $this->holdingToken($old)->getJson('/api/guest/order')->assertOk();
        $this->assertNull($order->fresh()->guestToken());
    }

    /** The test that fails if anyone puts the whole model back in the payload. */
    public function test_the_payload_carries_nothing_personal(): void
    {
        [$order] = $this->guestOrder();

        $payload = (new OrderBroadcast($order, 'update'))->broadcastWith();

        $this->assertSame(
            ['event' => 'update', 'order' => ['id' => $order->id, 'status' => OrderStatus::PLACED, 'number' => $order->number()]],
            $payload
        );

        $flat = json_encode($payload);

        foreach (['Julia', '09171234567', 'julia@example.test', 'ADD Street', '14.97', '120.76'] as $leak) {
            $this->assertStringNotContainsString($leak, $flat);
        }
    }

    // -----------------------------------------------------------------
    // What a guest may and may not do with their order
    // -----------------------------------------------------------------

    public function test_a_guest_can_cancel_and_is_told_the_refund_outcome(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->holdingToken($token)
            ->postJson('/api/guest/order/cancel')
            ->assertOk()
            ->assertJsonPath('refunded', true)
            ->assertJsonPath('order.is_cancelled', true);

        $fresh = $order->fresh();

        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        // Left empty on purpose: it is what makes the notice read as the
        // customer's own doing rather than the shop's.
        $this->assertNull($fresh->cancelled_by);
        $this->assertSame('350.00', $fresh->refund_owed);
    }

    public function test_a_guest_cannot_cancel_once_the_store_confirms(): void
    {
        [$order, $token] = $this->guestOrder(['status' => OrderStatus::PREPARING]);

        // BR-19 (decided 2026-11-17): only while placed.
        $this->holdingToken($token)
            ->postJson('/api/guest/order/cancel')
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'STORE_CONFIRMED');

        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    public function test_a_guest_can_correct_the_email_the_link_is_sent_to(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->holdingToken($token)
            ->patchJson('/api/guest/order', ['contact_email' => '  julia.v@example.test '])
            ->assertOk()
            ->assertJsonPath('message', 'Your email address for this order has been updated.');

        $this->assertSame('julia.v@example.test', $order->fresh()->guest_email);
    }

    public function test_a_malformed_email_is_refused(): void
    {
        [$order, $token] = $this->guestOrder();

        foreach (['', 'not-an-email'] as $email) {
            $this->holdingToken($token)
                ->patchJson('/api/guest/order', ['contact_email' => $email])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'CONTACT_EMAIL_INVALID');
        }

        $this->assertSame('julia@example.test', $order->fresh()->guest_email);
    }

    public function test_the_email_cannot_be_changed_once_the_order_closes(): void
    {
        [, $token] = $this->guestOrder(['status' => OrderStatus::COMPLETED, 'closed_at' => CarbonImmutable::now()]);

        $this->holdingToken($token)
            ->patchJson('/api/guest/order', ['contact_email' => 'new@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ORDER_ALREADY_CLOSED');
    }

    public function test_a_guest_may_change_only_the_contact_and_has_no_details_to_confirm(): void
    {
        [, $token] = $this->guestOrder();

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertOk()
            ->assertJsonPath('order.can_confirm_details', false)
            ->assertJsonPath('order.editable.contact_name', true)
            ->assertJsonPath('order.editable.contact_phone', true)
            ->assertJsonPath('order.editable.address', false)
            ->assertJsonPath('order.editable.note', false)
            ->assertJsonPath('order.editable.pickup_at', false);
    }

    public function test_a_guest_can_confirm_a_delivery_arrived(): void
    {
        [$order, $token] = $this->guestOrder([
            'order_type' => 'delivery',
            'status' => OrderStatus::ON_THE_WAY,
        ]);

        $this->holdingToken($token)
            ->postJson('/api/guest/order/received')
            ->assertOk();

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_a_pickup_order_cannot_be_confirmed_as_delivered(): void
    {
        [, $token] = $this->guestOrder();

        $this->holdingToken($token)
            ->postJson('/api/guest/order/received')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_A_DELIVERY');
    }
}
