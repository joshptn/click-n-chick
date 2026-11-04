<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

class NotificationBroadcast implements LiveBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public Notification $notification,
        public int $userId,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'notification',
            'user_id' => $this->userId,
            'notification' => $this->notification->toArray(),
        ];
    }
}
