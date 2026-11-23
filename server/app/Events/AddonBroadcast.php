<?php

namespace App\Events;

use App\Models\Addon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An add-on was created, changed, switched or deleted (decided 2026-11-24).
 *
 * Public, on the `menu` channel with dish changes, because the dish screen
 * offers add-ons to signed-out visitors too. It carries the id and the dishes
 * that offer it; menus, carts and the staff add-on lists refetch on it.
 */
class AddonBroadcast implements LiveBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public int $addonId;

    /** @var list<int> */
    public array $foodIds;

    /** @param  list<int>  $foodIds  read before a delete takes the links away */
    public function __construct(Addon $addon, public string $eventName, array $foodIds = [])
    {
        $this->addonId = (int) $addon->getKey();
        $this->foodIds = array_values(array_map('intval', $foodIds));
    }

    public function broadcastOn(): array
    {
        return [new Channel('menu')];
    }

    public function broadcastAs(): string
    {
        return 'addon';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->eventName,
            'addon' => ['id' => $this->addonId],
            'food_ids' => $this->foodIds,
        ];
    }
}
