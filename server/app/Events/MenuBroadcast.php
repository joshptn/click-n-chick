<?php

namespace App\Events;

use App\Models\Food;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MenuBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
