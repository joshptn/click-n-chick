<?php

namespace App\Http\Resources;

use App\Services\Orders\AmendmentPolicy;
use App\Services\Orders\CancellationPolicy;
use App\Services\Orders\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderTrackingResource extends JsonResource
{
    public function toArray($request): array
    {
        $status = (string) $this->status;
        $isCancelled = $status === OrderStatus::CANCELLED;
        $isDelivery = $this->order_type === 'delivery';

        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'order_type' => $this->order_type,

            'status' => $status,
            'status_label' => OrderStatus::label($status),
            'is_terminal' => $this->isTerminal(),
            'is_cancelled' => $isCancelled,
            'cancellation_reason' => $this->cancellation_reason,

            'steps' => $this->steps($status, $isCancelled),
            'step_index' => $isCancelled ? null : $this->stepIndex($status),
            'step_count' => count($this->statusChain()),

            'is_advance' => $this->isAdvance(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'payment_due_at' => $this->paymentDueAt()?->toIso8601String(),

            'queue' => [
                'number' => $this->queue_number,
                'label' => $this->queueLabel(),
                'in_line' => $this->isInLine(),
                'position' => $this->queuePosition(),
                'ahead' => $this->aheadInQueue(),
            ],

            'cancellation' => app(CancellationPolicy::class)->for($this->resource),
            'can_cancel' => ! $this->isTerminal(),
            'editable' => app(AmendmentPolicy::class)->editable($this->resource),
            'details_confirmed_at' => $this->details_confirmed_at?->toIso8601String(),
            'can_confirm_details' => app(AmendmentPolicy::class)->canConfirmDetails($this->resource),
            'refund_owed' => $this->refund_owed === null ? null : (float) $this->refund_owed,
            'cancelled_by_store' => $this->cancelled_by !== null && $this->cancelled_by !== $this->user_id,
            'can_confirm_receipt' => $isDelivery && $status === OrderStatus::ON_THE_WAY,

            'placed_at' => $this->created_at?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'estimated_time_of_completion' => $this->estimated_time_of_completion,

            'pickup' => $this->when(! $isDelivery, fn () => [
                'at' => $this->pickup_at?->toIso8601String(),
            ]),

            'delivery' => $this->when($isDelivery, fn () => [
                'full_address' => $this->full_address,
                'location' => $this->location,
                'note' => $this->delivery_note,
                'distance_km' => $this->delivery_distance_km,
            ]),

            'contact' => [
                'name' => $this->user
                    ? trim(($this->user->first_name ?? '').' '.($this->user->last_name ?? ''))
                    : $this->guest_name,
                'phone' => $this->user?->phone_number ?? $this->guest_phone,
            ],

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'food_name' => $item->food->food_name ?? 'Removed item',
                'thumbnail' => $item->food->thumbnail ?? null,
                'quantity' => $item->quantity,
                'unit_price' => (float) ($item->unit_price ?? $item->price),
                'subtotal' => (float) ($item->subtotal ?? ($item->price * $item->quantity)),
                'addons' => $item->relationLoaded('addons')
                    ? $item->addons->map(fn ($addon) => [
                        'id' => $addon->id,
                        'addon_name' => $addon->addon_name,
                    ])->values()
                    : [],
            ])->values()),

            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'delivery_fee' => (float) $this->delivery_fee,
            'total' => (float) ($this->total_amount ?? $this->total_price),
            'payment_status' => $this->payment_status,
        ];
    }

    private function steps(string $status, bool $isCancelled): array
    {
        $chain = $this->statusChain();
        $at = $isCancelled ? -1 : array_search($status, $chain, true);

        return array_map(fn (string $step, int $index) => [
            'key' => $step,
            'label' => OrderStatus::label($step),
            'state' => match (true) {
                $at === false || $at === -1 => 'upcoming',
                $index < $at => 'done',
                $index === $at => $this->isTerminal() ? 'done' : 'current',
                default => 'upcoming',
            },
        ], $chain, array_keys($chain));
    }

    private function stepIndex(string $status): ?int
    {
        $at = array_search($status, $this->statusChain(), true);

        return $at === false ? null : $at + 1;
    }
}
