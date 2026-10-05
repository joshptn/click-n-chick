<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class OrderBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $eventName,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('orders.'.$this->order->id),
            new PrivateChannel('admin.orders'),
        ];

        /**
         * A guest cannot authorize a private channel: /api/broadcasting/auth sits
         * behind auth:sanctum, and loosening that would hand every other channel
         * a null user to guard against. So a guest order also goes out on a public
         * channel whose name nobody can guess - derived one-way from the stored
         * verifier, so a captured subscribe frame cannot be replayed as the token.
         *
         * Expiry is enforced here because a public channel has no subscribe-time
         * gate to enforce it at. That is the cost of this choice, and the reason
         * it reads the same predicate the HTTP routes do rather than its own.
         */
        $guestChannel = $this->order->guestChannel();

        if ($guestChannel !== null && $this->order->isGuest() && $this->order->guestAccessIsLive()) {
            $channels[] = new Channel($guestChannel);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order';
    }

    /**
     * Only what a listener actually reads.
     *
     * This used to send the whole model, which on a guest order means their phone,
     * email and address - over a channel whose only protection is the secrecy of
     * its name. Nothing needed it: the staff dashboards read id and status, the
     * new-order toast reads the event and the id, and the tracking page and orders
     * list ignore the payload entirely and refetch. user_id had no reader at all.
     *
     * Reverb sends one payload per event to all of its channels, so trimming for
     * the guest channel means trimming for every channel - which also stops
     * pushing a customer's details to every staff browser on every order change.
     */
    public function broadcastWith(): array
    {
        return [
            'event' => $this->eventName,
            'order' => [
                'id' => $this->order->id,
                'status' => $this->order->status,
            ],
        ];
    }
}
