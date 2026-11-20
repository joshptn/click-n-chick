<?php

namespace App\Http\Resources;

use App\Services\Orders\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderQueueResource extends JsonResource
{
    public function toArray($request): array
    {
        $isDelivery = $this->order_type === 'delivery';

        return [
            'id' => $this->id,
            // "007" for the day's immediate orders (decided 2026-11-20).
            'order_number' => $this->order_number,
            'reference' => $this->number() ?? '#'.$this->id,

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

            // Whoever is actually collecting or receiving: the order's own
            // contact (BR-21b), which the customer may have changed since.
            'customer' => [
                'name' => $this->contactName(),
                'phone' => $this->contactPhone(),
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
                // The kitchen cooks to the add-ons, so they ride with the line.
                'addons' => $item->relationLoaded('addons')
                    ? $item->addons->pluck('addon_name')->values()
                    : [],
            ])->values()),

            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'delivery_fee' => $this->delivery_fee,
            'total_amount' => $this->total_amount,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->whenLoaded(
                'payments',
                fn () => $this->payments->sortByDesc('id')->first()?->payment_method
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
