<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The store's trading state changed: ordering paused or resumed, delivery
 * switched, or the hours moved.
 *
 * Public, like `menu`: the payload is the same snapshot GET /api/store/status
 * hands to anyone, so a signed-out visitor on the menu learns of it too. Taken
 * when the change is made rather than when the queue gets to it, so it reports
 * the state the staff member set.
 */
class StoreBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $store */
    public function __construct(public array $store) {}

    public function broadcastOn(): array
    {
        return [new Channel('store')];
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    public function broadcastWith(): array
    {
        return ['store' => $this->store];
    }
}
