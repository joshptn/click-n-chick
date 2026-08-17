<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A device's sessions were just revoked.
 *
 * Sent so the revoked browser leaves immediately instead of sitting on a
 * screen whose next request will 401. It is a courtesy, not the control: the
 * token is already dead server-side before this is dispatched, so a client
 * that ignores the event has still lost its access.
 *
 * Broadcast on the account channel every device already subscribes to, with
 * the device id in the payload - each client acts only if it is the one named.
 * Nothing secret travels here.
 */
class SessionRevoked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $deviceId,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'session.revoked';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'session.revoked',
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
        ];
    }
}
