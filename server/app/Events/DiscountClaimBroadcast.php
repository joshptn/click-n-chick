<?php

namespace App\Events;

use App\Models\Discount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A discount application arrived or was decided.
 *
 * Staff only. The Pending Verification queue and the sidebar count refetch on
 * it, so a claim another agent just settled leaves every open queue at once.
 * The payload names the claim and nothing else: listeners refetch, and the ID
 * photo and the customer's details stay behind the authorised endpoint.
 */
class DiscountClaimBroadcast implements LiveBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public const SUBMITTED = 'submitted';

    public const DECIDED = 'decided';

    public function __construct(
        public Discount $claim,
        public string $eventName,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.discounts')];
    }

    public function broadcastAs(): string
    {
        return 'claim';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->eventName,
            'claim' => [
                'id' => $this->claim->id,
                'status' => $this->claim->discount_status,
            ],
        ];
    }
}
