<?php

namespace App\Http\Resources;

use App\Services\Orders\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvanceRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        $timezone = (string) config('store.timezone', 'Asia/Manila');
        $collectAt = $this->scheduled_for?->setTimezone($timezone);

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'reference' => '#'.$this->id,

            'status' => $this->status,
            'status_label' => OrderStatus::label((string) $this->status),
            'next_status' => $this->nextStatus(),
            'allowed_transitions' => OrderStatus::advanceTransitionsFrom((string) $this->status),

            'scheduled_for' => $collectAt?->toIso8601String(),
            'collection_date' => $collectAt?->toDateString(),
            'collection_time' => $collectAt?->format('H:i'),
            'days_until_collection' => $collectAt
                ? (int) now($timezone)->startOfDay()->diffInDays($collectAt->startOfDay(), false)
                : null,
            'is_due_today' => $collectAt?->isSameDay(now($timezone)) ?? false,

            'customer' => [
                'name' => trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? '')),
                'phone' => $this->user?->phone_number,
            ],

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'food_name' => $item->food->food_name ?? 'Removed item',
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
            ])->values()),

            'item_count' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),

            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'payment_status' => $this->payment_status,
            'is_paid' => $this->isPaid(),
            'accepted_at' => $this->accepted_at?->setTimezone($timezone)->toIso8601String(),
            'payment_due_at' => $this->paymentDueAt()?->setTimezone($timezone)->toIso8601String(),
            'payment_overdue' => $this->isPaymentOverdue(),

            'cancellation_reason' => $this->cancellation_reason,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
