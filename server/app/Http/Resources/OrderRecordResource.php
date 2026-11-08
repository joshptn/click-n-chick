<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Orders\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A closed order as Order Records shows it.
 *
 * The list row is light: who, what, how it ended and what it came to. The
 * detailed form adds the priced lines, the contact details, the payment and the
 * cancellation - read from the order's own snapshot columns, so the figures are
 * the ones the customer was charged rather than today's menu prices.
 */
class OrderRecordResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(): self
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray($request): array
    {
        $status = (string) $this->status;
        $isDelivery = $this->order_type === 'delivery';
        $items = $this->relationLoaded('items') ? $this->items : collect();

        $row = [
            'id' => $this->id,
            'reference' => $this->reference(),
            'queue_label' => $this->queueLabel(),
            'order_number' => $this->order_number,

            'status' => $status,
            'status_label' => OrderStatus::label($status),
            'outcome' => $this->outcome($status),

            'order_type' => $this->order_type,
            'is_advance' => $this->isAdvance(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),

            'customer' => [
                'name' => $this->customerName(),
                'is_guest' => $this->user_id === null,
            ],

            'item_count' => (int) ($this->item_count ?? $items->sum('quantity')),
            'items_preview' => $items->take(2)->map(fn ($item) => [
                'food_name' => $item->food->food_name ?? 'Removed item',
                'quantity' => (int) $item->quantity,
            ])->values(),
            'line_count' => $items->count(),

            'total' => (float) ($this->total_amount ?? $this->total_price ?? 0),
            'discount_amount' => (float) $this->discount_amount,
            'payment_status' => $this->payment_status,
            'payment_verified' => (bool) ($this->payment_verified ?? false),
            'refund_owed' => $this->refund_owed === null ? null : (float) $this->refund_owed,
            'cancelled_by_store' => $this->cancelledByStore(),
            'cancellation_reason' => $this->cancellation_reason,

            'placed_at' => $this->created_at?->toIso8601String(),
            // Rows from before closed_at was stamped closed at their last update.
            'closed_at' => ($this->closed_at ?? $this->updated_at)?->toIso8601String(),
        ];

        if (! $this->detailed) {
            return $row;
        }

        $payment = $this->relationLoaded('payments') ? $this->payments->sortByDesc('id')->first() : null;
        $canceller = $this->relationLoaded('cancelledBy') ? $this->cancelledBy : null;

        return $row + [
            'contact' => [
                'email' => $this->user?->email ?? $this->guest_email,
                'phone' => $this->user?->phone_number ?? $this->guest_phone,
            ],

            'pickup_at' => $this->when(! $isDelivery, fn () => $this->pickup_at?->toIso8601String()),
            'delivery' => $this->when($isDelivery, fn () => [
                'full_address' => $this->full_address,
                'location' => $this->location,
                'note' => $this->delivery_note,
                'distance_km' => $this->delivery_distance_km === null ? null : (float) $this->delivery_distance_km,
            ]),

            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'food_name' => $item->food->food_name ?? 'Removed item',
                'thumbnail' => $item->food->thumbnail ?? null,
                'quantity' => (int) $item->quantity,
                // unit_price already includes the add-ons; each add-on keeps the
                // price it was sold at on the pivot.
                'unit_price' => (float) ($item->unit_price ?? $item->price),
                'subtotal' => (float) ($item->subtotal ?? (($item->unit_price ?? $item->price) * $item->quantity)),
                'addons' => $item->relationLoaded('addons')
                    ? $item->addons->map(fn ($addon) => [
                        'id' => $addon->id,
                        'addon_name' => $addon->addon_name,
                        'unit_price' => $addon->pivot?->unit_price === null ? null : (float) $addon->pivot->unit_price,
                    ])->values()
                    : [],
            ])->values(),

            'subtotal' => (float) $this->subtotal,
            'delivery_fee' => (float) $this->delivery_fee,

            'payment' => [
                'status' => $this->payment_status,
                'method' => $payment?->payment_method,
                'paid_at' => $payment?->paid_at?->toIso8601String(),
                'refund_amount' => $payment?->refund_amount === null ? null : (float) $payment->refund_amount,
                'refund_status' => $payment?->refund_status,
                'refunded_at' => $payment?->refunded_at?->toIso8601String(),
            ],

            // Staff only: a customer cancelling their own order is not named.
            'cancelled_by_name' => $canceller && in_array($canceller->role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)
                ? (trim(($canceller->first_name ?? '').' '.($canceller->last_name ?? '')) ?: null)
                : null,

            'timeline' => [
                'placed_at' => $this->created_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'queued_at' => $this->queued_at?->toIso8601String(),
                'details_confirmed_at' => $this->details_confirmed_at?->toIso8601String(),
                'closed_at' => ($this->closed_at ?? $this->updated_at)?->toIso8601String(),
            ],
        ];
    }

    /** completed | cancelled | rejected - how the record ended. */
    private function outcome(string $status): string
    {
        return match ($status) {
            OrderStatus::COMPLETED, OrderStatus::DELIVERED => 'completed',
            OrderStatus::REJECTED => 'rejected',
            default => 'cancelled',
        };
    }

    private function customerName(): ?string
    {
        if ($this->user) {
            $name = trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? ''));

            return $name === '' ? null : $name;
        }

        return $this->guest_name;
    }

    private function cancelledByStore(): bool
    {
        return $this->cancelled_by !== null && (int) $this->cancelled_by !== (int) $this->user_id;
    }
}
