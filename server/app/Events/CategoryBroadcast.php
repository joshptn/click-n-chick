<?php

namespace App\Events;

use App\Models\Category;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A menu category was created, renamed or deleted (UC-MENU-007..009).
 *
 * Public, on the same `menu` channel as dish changes, because the category tabs
 * are read by signed-out visitors too. It carries the id and name only - a
 * deleted category has no row left to load - and pages refetch on it.
 */
class CategoryBroadcast implements LiveBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @var array{id: int, name: string} */
    public array $category;

    public function __construct(Category $category, public string $eventName)
    {
        $this->category = ['id' => (int) $category->getKey(), 'name' => (string) $category->name];
    }

    public function broadcastOn(): array
    {
        return [new Channel('menu')];
    }

    public function broadcastAs(): string
    {
        return 'category';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->eventName,
            'category' => $this->category,
        ];
    }
}
