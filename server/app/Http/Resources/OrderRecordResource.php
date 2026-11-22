<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\AdvanceExpiry;
use App\Services\Orders\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

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
        [$paid, $extras] = $this->confirmedPayments();

        $row = [
            'id' => $this->id,
            // Order Records goes by the order id alone (decided 2026-11-20):
            // the daily numbers repeat every day.
            'reference' => '#'.$this->id,

            'status' => $status,
            'status_label' => OrderStatus::label($status),
            'outcome' => $this->outcome($status),

            'order_type' => $this->order_type,
            'is_advance' => $this->isAdvance(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),

            'customer' => [
                'name' => $this->customerName(),
                'is_guest' => $this->user_id === null && $this->contact_name !== Order::ANONYMIZED_CUSTOMER,
            ],

            'item_count' => (int) ($this->item_count ?? $items->sum('quantity')),
            'items_preview' => $items->take(2)->map(fn ($item) => [
                'food_name' => $item->food->food_name ?? 'Removed item',
                'quantity' => (int) $item->quantity,
            ])->values(),
            'line_count' => $items->count(),

            'total' => (float) ($this->total_amount ?? $this->total_price ?? 0),
            'discount_amount' => (float) $this->discount_amount,
            'payment_status' => $paid === null ? 'unpaid' : 'paid',
            'payment_verified' => (bool) ($this->payment_verified ?? $paid !== null),
            // Decided 2026-11-23: the refund lives on the payment. An extra
            // payment's refund is listed apart from the order's own.
            'refund' => $paid?->refundSummary(),
            'extra_refunds' => $extras->map(fn ($payment) => $payment->refundSummary())->filter()->values(),
            'cancelled_by_store' => $this->cancelledByStore(),
            'expired_unpaid' => AdvanceExpiry::expired($status, $this->cancellation_reason, $this->cancelled_by),
            'cancellation_reason' => $this->cancellation_reason,

            'placed_at' => $this->created_at?->toIso8601String(),
            // Rows from before closed_at was stamped closed at their last update.
            'closed_at' => ($this->closed_at ?? $this->updated_at)?->toIso8601String(),
        ];

        if (! $this->detailed) {
            return $row;
        }

        $canceller = $this->relationLoaded('cancelledBy') ? $this->cancelledBy : null;

        return $row + [
            'contact' => [
                'email' => $this->user?->email ?? $this->guest_email,
                'phone' => $this->contactPhone(),
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

            // The payment that paid for the order, not the newest attempt
            // (decided 2026-11-23); any later one is an extra payment, refunded.
            'payment' => [
                'status' => $paid === null ? 'unpaid' : 'paid',
                ...$this->paymentDetails($paid),
                'extra_payments' => $extras->map(fn ($payment) => $this->paymentDetails($payment))->values(),
            ],

            // Staff only: a customer cancelling their own order is not named.
            'cancelled_by_name' => $canceller && in_array($canceller->role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)
                ? (trim(($canceller->first_name ?? '').' '.($canceller->last_name ?? '')) ?: null)
                : null,

            'timeline' => [
                'placed_at' => $this->created_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'details_confirmed_at' => $this->details_confirmed_at?->toIso8601String(),
                'closed_at' => ($this->closed_at ?? $this->updated_at)?->toIso8601String(),
            ],
        ];
    }

    /**
     * The payment that paid for the order and any extra ones, from the loaded
     * payments: the first confirmed is the order's, as Order::paidPayment.
     *
     * @return array{0: ?Payment, 1: Collection<int, Payment>}
     */
    private function confirmedPayments(): array
    {
        if (! $this->relationLoaded('payments')) {
            $paid = $this->relationLoaded('paidPayment') ? $this->paidPayment : null;

            return [$paid, collect()];
        }

        $confirmed = $this->payments
            ->filter(fn (Payment $payment) => $payment->isVerified())
            ->sortBy(fn (Payment $payment) => sprintf('%015d-%015d', $payment->paid_at->getTimestamp(), $payment->id))
            ->values();

        return [$confirmed->first(), $confirmed->slice(1)->values()];
    }

    /** @return array<string, mixed> */
    private function paymentDetails(?Payment $payment): array
    {
        $xenditId = (string) $payment?->idempotency_key;

        return [
            'id' => $payment?->getKey(),
            'method' => $payment?->payment_method,
            'paid_at' => $payment?->paid_at?->toIso8601String(),
            // What Xendit's dashboard searches by.
            'xendit_payment_id' => str_starts_with($xenditId, 'py-') ? $xenditId : null,
            'refund' => $payment?->refundSummary(),
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

        return $this->contact_name;
    }

    private function cancelledByStore(): bool
    {
        return $this->cancelled_by !== null && (int) $this->cancelled_by !== (int) $this->user_id;
    }
}
