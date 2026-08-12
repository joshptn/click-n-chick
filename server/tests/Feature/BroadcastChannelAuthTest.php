<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u->fresh();
    }

    private function order(?User $owner): Order
    {
        return Order::create([
            'user_id' => $owner?->id,
            'total_price' => 100,
            'status' => 'pending',
        ]);
    }

    // ---------------------------------------------------------------
    // orders.{orderId} - ownership OR admin. Neither alone suffices for
    // a non-owner customer.
    // ---------------------------------------------------------------

    public function test_order_owner_may_subscribe(): void
    {
        $owner = $this->user('customer');
        $order = $this->order($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'private-orders.' . $order->id,
                'socket_id' => '123.456',
            ])
            ->assertOk();
    }

    public function test_authenticated_customer_who_is_not_the_owner_is_rejected(): void
    {
        $owner = $this->user('customer');
        $stranger = $this->user('customer');
        $order = $this->order($owner);

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'private-orders.' . $order->id,
                'socket_id' => '123.456',
            ])
            ->assertForbidden();
    }

    public function test_admin_may_subscribe_to_any_order(): void
    {
        $owner = $this->user('customer');
        $order = $this->order($owner);

        foreach (['admin', 'super_admin'] as $role) {
            $this->actingAs($this->user($role), 'sanctum')
                ->postJson('/api/broadcasting/auth', [
                    'channel_name' => 'private-orders.' . $order->id,
                    'socket_id' => '123.456',
                ])
                ->assertOk();
        }
    }

    public function test_guest_order_is_not_subscribable_by_a_random_customer(): void
    {
        $order = $this->order(null); // guest checkout: user_id is null
        $stranger = $this->user('customer');

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'private-orders.' . $order->id,
                'socket_id' => '123.456',
            ])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // admin.orders - admin / super_admin only
    // ---------------------------------------------------------------

    public function test_admin_firehose_allows_admin_roles_only(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $this->actingAs($this->user($role), 'sanctum')
                ->postJson('/api/broadcasting/auth', [
                    'channel_name' => 'private-admin.orders',
                    'socket_id' => '123.456',
                ])
                ->assertOk();
        }

        $this->actingAs($this->user('customer'), 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'private-admin.orders',
                'socket_id' => '123.456',
            ])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // notifications.{userId} - recipient only, NOT admin
    // ---------------------------------------------------------------

    public function test_notification_channel_allows_only_the_recipient(): void
    {
        $recipient = $this->user('customer');

        $this->actingAs($recipient, 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'private-notifications.' . $recipient->id,
                'socket_id' => '123.456',
            ])
            ->assertOk();
    }

    public function test_admin_is_rejected_from_another_users_notifications(): void
    {
        $recipient = $this->user('customer');

        foreach (['admin', 'super_admin'] as $role) {
            $this->actingAs($this->user($role), 'sanctum')
                ->postJson('/api/broadcasting/auth', [
                    'channel_name' => 'private-notifications.' . $recipient->id,
                    'socket_id' => '123.456',
                ])
                ->assertForbidden();
        }
    }

    // ---------------------------------------------------------------
    // guests
    // ---------------------------------------------------------------

    public function test_guest_cannot_authorize_any_private_channel(): void
    {
        $order = $this->order($this->user('customer'));

        // auth:sanctum rejects before the channel callback is ever reached.
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-orders.' . $order->id,
            'socket_id' => '123.456',
        ])->assertUnauthorized();
    }
}
