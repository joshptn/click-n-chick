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
            'guest_name' => 'Julia Veneese',
            'guest_phone' => '09171234567',
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

    public function test_an_open_order_stays_reachable_so_the_right_to_cancel_survives(): void
    {
        [$order, $token] = $this->guestOrder();

        $this->travelTo(CarbonImmutable::now()->addDays(20));

        $this->holdingToken($token)->getJson('/api/guest/order')->assertOk();

        $this->assertTrue($order->fresh()->guestAccessIsLive());
    }

    public function test_an_open_order_stops_being_reachable_at_the_ceiling(): void
    {
        [, $token] = $this->guestOrder();

        $this->travelTo(CarbonImmutable::now()->addDays(31));

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'TRACKING_LINK_EXPIRED');
    }

    public function test_a_closed_order_stays_reachable_through_the_grace_window(): void
    {
        [, $token] = $this->guestOrder([
            'status' => OrderStatus::COMPLETED,
            'closed_at' => CarbonImmutable::now(),
        ]);

        $this->travelTo(CarbonImmutable::now()->addDays(6));

        $this->holdingToken($token)->getJson('/api/guest/order')->assertOk();
    }

    public function test_a_closed_order_is_gone_once_the_grace_window_passes(): void
    {
        [, $token] = $this->guestOrder([
            'status' => OrderStatus::COMPLETED,
            'closed_at' => CarbonImmutable::now(),
        ]);

        $this->travelTo(CarbonImmutable::now()->addDays(8));

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertStatus(410);
    }

    public function test_both_windows_come_from_configuration(): void
    {
        config()->set('store.guest.track_days_after_close', 1);

        [, $token] = $this->guestOrder([
            'status' => OrderStatus::COMPLETED,
            'closed_at' => CarbonImmutable::now(),
        ]);

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

        $this->travelTo(CarbonImmutable::now()->addDays(31));

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

    public function test_rotating_the_token_moves_the_channel(): void
    {
        [$order] = $this->guestOrder();

        $before = $order->guestChannel();

        $order->mintGuestToken();

        $this->assertNotSame($before, $order->fresh()->guestChannel());
    }

    /** The test that fails if anyone puts the whole model back in the payload. */
    public function test_the_payload_carries_nothing_personal(): void
    {
        [$order] = $this->guestOrder();

        $payload = (new OrderBroadcast($order, 'update'))->broadcastWith();

        $this->assertSame(
            ['event' => 'update', 'order' => ['id' => $order->id, 'status' => OrderStatus::PLACED]],
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

    public function test_a_guest_cancelling_after_the_kitchen_starts_gets_no_refund(): void
    {
        [$order, $token] = $this->guestOrder(['status' => OrderStatus::PREPARING]);

        $this->holdingToken($token)
            ->postJson('/api/guest/order/cancel')
            ->assertOk()
            ->assertJsonPath('refunded', false);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_guest_is_offered_no_amendments_and_no_details_to_confirm(): void
    {
        [, $token] = $this->guestOrder();

        $this->holdingToken($token)
            ->getJson('/api/guest/order')
            ->assertOk()
            ->assertJsonPath('order.can_confirm_details', false)
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

    // -----------------------------------------------------------------
    // Pruning
    // -----------------------------------------------------------------

    public function test_the_prune_clears_only_lapsed_tokens(): void
    {
        [$live] = $this->guestOrder();
        [$lapsed] = $this->guestOrder([
            'status' => OrderStatus::COMPLETED,
            'closed_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $this->artisan('guest:prune-tokens')->assertSuccessful();

        $this->assertNotNull($live->fresh()->guest_token_hash);
        $this->assertNull($lapsed->fresh()->guest_token_hash);
    }

    public function test_the_dry_run_clears_nothing(): void
    {
        [$lapsed] = $this->guestOrder([
            'status' => OrderStatus::COMPLETED,
            'closed_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $this->artisan('guest:prune-tokens --dry-run')->assertSuccessful();

        $this->assertNotNull($lapsed->fresh()->guest_token_hash);
    }
}
