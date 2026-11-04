<?php

namespace App\Events;

use App\Models\Food;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class MenuBroadcast implements LiveBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public Food $food,
        public string $eventName,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('menu')];
    }

    public function broadcastAs(): string
    {
        return 'food';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->eventName,
            'food' => $this->food->toArray(),
        ];
    }
}
