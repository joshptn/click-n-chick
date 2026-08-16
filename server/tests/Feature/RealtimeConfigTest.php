<?php

namespace Tests\Feature;

use App\Events\MenuBroadcast;
use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Food;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The broadcasting contract the browser depends on (GATE-3).
 *
 * These lock down the things a client cannot discover for itself: which
 * channel an event goes to, what it is named on the wire, and what the payload
 * contains. Renaming any of them silently stops a subscribed browser from ever
 * hearing the event again - the connection stays healthy and nothing errors.
 *
 * Delivery itself is NOT verified here. It cannot be: asserting a fake was
 * dispatched proves nothing about a websocket. That is what the Playwright
 * suite in client/e2e/realtime.spec.js is for.
 */
class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        return Order::create([
            'user_id' => User::factory()->create()->id,
            'total_price' => 100,
            'status' => 'pending',
        ]);
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    public function test_reverb_is_the_configured_broadcaster_outside_of_tests(): void
    {
        // phpunit.xml does not override BROADCAST_CONNECTION, so this reads
        // what the application would actually use.
        $this->assertSame('reverb', config('broadcasting.default'));

        foreach (['key', 'secret', 'app_id'] as $credential) {
            $this->assertNotEmpty(
                config("broadcasting.connections.reverb.{$credential}"),
                "REVERB_APP_".strtoupper($credential)." is not set; the client cannot connect."
            );
        }
    }

    public function test_the_broadcasting_auth_route_is_registered_under_the_api_prefix(): void
    {
        // The SPA authenticates with bearer tokens, so the endpoint has to sit
        // in the api group. At /broadcasting/auth it would be in the web group
        // and every private subscription would fail.
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => $route->uri() === 'api/broadcasting/auth'
            ),
            'api/broadcasting/auth is not registered.'
        );
    }

    // -----------------------------------------------------------------
    // The wire contract
    // -----------------------------------------------------------------

    public function test_order_events_go_to_the_owner_channel_and_the_staff_firehose(): void
    {
        $order = $this->order();
        $event = new OrderBroadcast($order, 'update');

        $names = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        // Both, not one: the customer watches their order, staff watch all of
        // them, and a single channel could not serve both without leaking.
        $this->assertContains('private-orders.'.$order->id, $names);
        $this->assertContains('private-admin.orders', $names);

        // Echo listens for ".order"; the leading dot is stripped on the wire.
        $this->assertSame('order', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame('update', $payload['event']);
        $this->assertSame($order->id, $payload['order']['id']);
        $this->assertArrayHasKey('status', $payload['order']);
    }

    public function test_notification_events_are_private_to_one_recipient(): void
    {
        $user = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Order update',
            'body' => 'Your order is being prepared.',
            'is_read' => false,
        ]);

        $event = new NotificationBroadcast($notification, (int) $user->id);

        $channels = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-notifications.'.$user->id, $channels[0]->name);
        $this->assertSame('notification', $event->broadcastAs());
        $this->assertSame('Your order is being prepared.', $event->broadcastWith()['notification']['body']);
    }

    public function test_menu_events_are_public_because_guests_browse_the_menu(): void
    {
        $food = Food::create([
            'food_name' => 'Chicken Inasal',
            'description' => 'Grilled.',
            'price' => 160,
            'thumbnail' => 'https://example.test/a.jpg',
            'stock_quantity' => 3,
            'is_available' => true,
        ]);

        $event = new MenuBroadcast($food, 'updated');
        $channel = $event->broadcastOn()[0];

        // Public on purpose: live availability has to reach signed-out
        // visitors too, and it carries no personal data.
        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertNotInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('menu', $channel->name);
        $this->assertSame('food', $event->broadcastAs());

        // The card greys out on these, so they must survive the broadcast.
        $payload = $event->broadcastWith();
        $this->assertArrayHasKey('is_orderable', $payload['food']);
        $this->assertArrayHasKey('stock_status', $payload['food']);
    }

    public function test_every_broadcast_event_implements_should_broadcast(): void
    {
        foreach ([OrderBroadcast::class, NotificationBroadcast::class, MenuBroadcast::class] as $event) {
            $this->assertTrue(
                is_subclass_of($event, ShouldBroadcast::class),
                $event.' will never reach Reverb.'
            );
        }
    }

    // -----------------------------------------------------------------
    // The dispatch sites the UI depends on
    // -----------------------------------------------------------------

    public function test_changing_an_order_status_dispatches_a_broadcast(): void
    {
        Event::fake([OrderBroadcast::class]);

        $order = $this->order();

        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($agent, 'sanctum')
            ->putJson("/api/order/{$order->id}/status", ['status' => 'approved'])
            ->assertOk();

        Event::assertDispatched(
            OrderBroadcast::class,
            fn (OrderBroadcast $event) => $event->order->id === $order->id
        );
    }

    public function test_changing_stock_dispatches_a_menu_broadcast(): void
    {
        Event::fake([MenuBroadcast::class]);

        $food = Food::create([
            'food_name' => 'Chicken Inasal',
            'description' => 'Grilled.',
            'price' => 160,
            'thumbnail' => 'https://example.test/a.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
        ]);

        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/stock", ['stock_quantity' => 0])
            ->assertOk();

        // This is what makes a sold-out card grey out on an open menu without
        // a refresh.
        Event::assertDispatched(MenuBroadcast::class);
    }
}
