<?php

namespace App\Http\Resources;

use App\Http\Controllers\AdvanceRequestController;
use App\Services\Orders\AdvanceExpiry;
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
            // "A-007" once accepted; nothing before (decided 2026-11-20): a
            // request is known by its customer's name until then.
            'advance_order_number' => $this->advance_order_number,
            'reference' => $this->number(),

            'status' => $this->status,
            'status_label' => OrderStatus::label((string) $this->status),
            'next_status' => $this->nextStatus(),
            'allowed_transitions' => OrderStatus::advanceTransitionsFrom((string) $this->status),

            'scheduled_for' => $collectAt?->toIso8601String(),
            'collection_date' => $collectAt?->toDateString(),
            'collection_time' => $collectAt?->format('H:i'),
            'days_until_collection' => $daysUntil,
            'is_due_today' => $collectAt?->isSameDay(now($timezone)) ?? false,
            // The collection time itself, the moment an unanswered request expires (BR-16h).
            'collection_passed' => $collectAt !== null && $collectAt->lessThanOrEqualTo(now()),
            // Decided 2026-11-20: a paid order is started on its collection day,
            // straight from To Confirm or from the Scheduled Orders list; one for
            // a later day is scheduled first. Once its day has passed it can only
            // be cancelled.
            'can_start_preparing' => in_array($this->status, [OrderStatus::CONFIRMED, OrderStatus::SCHEDULED], true)
                && $this->isPaid()
                && $daysUntil === 0,
            'can_schedule' => $this->status === OrderStatus::CONFIRMED
                && $this->isPaid()
                && $daysUntil !== null
                && $daysUntil > 0,
            'collection_day_passed' => $daysUntil !== null && $daysUntil < 0,

            'customer' => [
                'name' => trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? '')),
                'phone' => $this->user?->phone_number,
            ],

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'food_name' => $item->food->food_name ?? 'Removed item',
                'quantity' => $item->quantity,
                // Units whose dish is paid with points (decided 2026-11-24).
                'redeemed_quantity' => (int) $item->redeemed_quantity,
                'subtotal' => $item->subtotal,
                // Each with the size picked: "Iced Tea (Large)".
                'addons' => $item->relationLoaded('addons')
                    ? $item->addons->map(fn ($addon) => $addon->chosen_name)->values()
                    : [],
            ])->values()),

            'item_count' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),

            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            // The VAT part of discount_amount, shown as its own line.
            'vat_exempt_amount' => (float) ($this->vat_exempt_amount ?? 0),
            'points_amount' => (float) $this->points_amount,
            'total_amount' => $this->total_amount,
            'payment_status' => $this->paymentStatus(),
            'is_paid' => $this->isPaid(),
            // GCash refunds go back through Xendit; QR Ph is refunded by hand (decided 2026-11-23).
            'payment_method' => $this->paidPayment?->payment_method,
            'accepted_at' => $this->accepted_at?->setTimezone($timezone)->toIso8601String(),
            'preparation_time' => $this->preparation_time,
            // Collection less preparation time, rather than the 24-hour rule.
            'pays_before_preparation' => $this->paysBeforePreparation(),
            'payment_due_at' => $this->paymentDueAt()?->setTimezone($timezone)->toIso8601String(),
            'payment_overdue' => $this->isPaymentOverdue(),
            'last_reminded_at' => $remindedAt?->toIso8601String(),
            'can_remind_at' => $remindableAt?->toIso8601String(),

            'cancellation_reason' => $this->cancellation_reason,
            'expired_unpaid' => AdvanceExpiry::expired((string) $this->status, $this->cancellation_reason, $this->cancelled_by),
            // Whether the shop closed it (rejection, staff cancellation) rather
            // than the customer or the payment deadline, which leave it empty.
            'cancelled_by_store' => $this->cancelled_by !== null
                && (int) $this->cancelled_by !== (int) $this->user_id,
            // The refund on the payment, decided 2026-11-23 (amount, state, method).
            'refund' => $this->paidPayment?->refundSummary(),
            'closed_at' => $this->closed_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
