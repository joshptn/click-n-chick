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
            'cancellation_reason' => $this->cancellation_reason,

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
                // Units whose dish was paid with points (decided 2026-11-24).
                'redeemed_quantity' => (int) $item->redeemed_quantity,
                // The kitchen cooks to the add-ons, so they ride with the line,
                // each with the size picked: "Iced Tea (Large)".
                'addons' => $item->relationLoaded('addons')
                    ? $item->addons->map(fn ($addon) => $addon->chosen_name)->values()
                    : [],
            ])->values()),

            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            // The VAT part of discount_amount, shown as its own line.
            'vat_exempt_amount' => (float) ($this->vat_exempt_amount ?? 0),
            // The part of the bill paid with points, in pesos.
            'points_amount' => (float) $this->points_amount,
            'delivery_fee' => $this->delivery_fee,
            'total_amount' => $this->total_amount,
            'payment_status' => $this->paymentStatus(),
            // The payment that paid for the order (decided 2026-11-23), not the newest attempt.
            'payment_method' => $this->whenLoaded(
                'paidPayment',
                fn () => $this->paidPayment?->payment_method
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
