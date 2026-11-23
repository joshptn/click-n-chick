<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Menu prices changed: one dish edited, or a bulk change (FR-02.5, FR-07.7).
 *
 * Public, on `menu` beside the stock updates, so every open menu, cart and
 * checkout hears it, a guest's included. Carts are priced on every read, so
 * refetching is all it takes for their totals to float. The message is the
 * notice the accounts receive, for a page that wants to say so.
 */
class PriceBroadcast implements LiveBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @param  list<int>  $foodIds */
    public function __construct(
        public string $message,
        public array $foodIds,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('menu')];
    }

    public function broadcastAs(): string
    {
        return 'prices';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'food_ids' => $this->foodIds,
        ];
    }
}
