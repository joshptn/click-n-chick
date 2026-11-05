<?php

namespace App\Http\Resources;

use App\Http\Controllers\AdvanceRequestController;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvanceRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        $timezone = (string) config('store.timezone', 'Asia/Manila');
        $collectAt = $this->scheduled_for?->toImmutable()->setTimezone($timezone);
        $daysUntil = $collectAt
            ? (int) now($timezone)->startOfDay()->diffInDays($collectAt->startOfDay(), false)
            : null;

        // Only present when the list asked for it (withMax in the controller).
        $remindedAt = $this->last_reminded_at
            ? CarbonImmutable::parse($this->last_reminded_at)->setTimezone($timezone)
            : null;
        $remindableAt = $remindedAt?->addMinutes(AdvanceRequestController::REMINDER_COOLDOWN_MINUTES);

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
            'days_until_collection' => $daysUntil,
            'is_due_today' => $collectAt?->isSameDay(now($timezone)) ?? false,
            'collection_passed' => $daysUntil !== null && $daysUntil < 0,
            // UC-ADV-012 and BR-16g: on or after the collection date, and paid.
            'can_start_preparing' => $this->status === OrderStatus::SCHEDULED
                && $this->isPaid()
                && $daysUntil !== null
                && $daysUntil <= 0,

            'customer' => [
                'name' => trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? '')),
                'phone' => $this->user?->phone_number,
            ],

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'food_name' => $item->food->food_name ?? 'Removed item',
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
                'addons' => $item->relationLoaded('addons')
                    ? $item->addons->pluck('addon_name')->values()
                    : [],
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
            'last_reminded_at' => $remindedAt?->toIso8601String(),
            'can_remind_at' => $remindableAt?->toIso8601String(),

            'cancellation_reason' => $this->cancellation_reason,
            // Whether the shop closed it (rejection, staff cancellation) rather
            // than the customer or the payment deadline, which leave it empty.
            'cancelled_by_store' => $this->cancelled_by !== null
                && (int) $this->cancelled_by !== (int) $this->user_id,
            'refund_owed' => $this->refund_owed,
            'closed_at' => $this->closed_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
