<?php

namespace App\Http\Resources;

use App\Services\Orders\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderQueueResource extends JsonResource
{
    private ?int $position = null;

    public function withPosition(?int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function toArray($request): array
    {
        $isDelivery = $this->order_type === 'delivery';

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,

            'queue_number' => $this->queue_number,
            'queue_label' => $this->queueLabel(),
            'queue_position' => $this->position,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'waiting_minutes' => $this->queued_at?->diffInMinutes(now()),

            'status' => $this->status,
            'status_label' => OrderStatus::label((string) $this->status),
            'next_status' => $this->nextStatus(),
            'allowed_transitions' => OrderStatus::transitionsFrom((string) $this->status, $this->order_type),
            'cancellation_reason' => $this->cancellation_reason,

            // The customer has checked their own details. Not a gate - the
            // kitchen may start regardless - but it is the difference between
            // starting on a confirmed order and one still being edited.
            'details_confirmed_at' => $this->details_confirmed_at?->toIso8601String(),

            'order_type' => $this->order_type,
            'estimated_time_of_completion' => $this->estimated_time_of_completion,

            // Whoever is actually collecting or receiving. Falls back to the
            // guest fields, which are the only contact a guest order has.
            'customer' => [
                'name' => $this->user
                    ? trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? ''))
                    : $this->guest_name,
                'phone' => $this->user?->phone_number ?? $this->guest_phone,
                'is_guest' => $this->user_id === null,
            ],

            'pickup_at' => $this->when(! $isDelivery, fn () => $this->pickup_at?->toIso8601String()),

            'delivery' => $this->when($isDelivery, fn () => [
                'full_address' => $this->full_address,
                'location' => $this->location,
                'note' => $this->delivery_note,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'distance_km' => $this->delivery_distance_km,
            ]),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'food_name' => $item->food->food_name ?? 'Removed item',
                'quantity' => $item->quantity,
            ])->values()),

            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'delivery_fee' => $this->delivery_fee,
            'total_amount' => $this->total_amount,
            'payment_status' => $this->payment_status,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
