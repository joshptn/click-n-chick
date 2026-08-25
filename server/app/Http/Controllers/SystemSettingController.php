<?php

namespace App\Http\Controllers;

use App\Events\NotificationBroadcast;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\CancellationPolicy;
use App\Services\Orders\DeliveryPricing;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class SystemSettingController extends Controller
{
    public function showDiscount()
    {
        return response()->json($this->discountPayload());
    }

    public function updateDiscount(Request $request)
    {
        $validated = $request->validate([
            'percentage' => [
                'required',
                'numeric',
                'min:'.Discount::MINIMUM_PERCENTAGE,
                'max:100',
            ],
        ], [
            'percentage.min' => 'The discount rate cannot go below the statutory '
                .Discount::MINIMUM_PERCENTAGE.'%.',
        ]);

        $previous = Discount::currentPercentage();
        $next = round((float) $validated['percentage'], 2);

        if ($previous === $next) {
            return response()->json([
                'success' => true,
                'message' => 'The discount rate is already '.$this->format($next).'%.',
            ] + $this->discountPayload());
        }

        Setting::put(Setting::DISCOUNT_PERCENTAGE, $next, (int) $request->user()->getKey());

        $this->announceRateChange($previous, $next);

        return response()->json([
            'success' => true,
            'message' => 'The statutory discount rate is now '.$this->format($next).'%.',
        ] + $this->discountPayload());
    }

    public function showDelivery()
    {
        return response()->json($this->deliveryPayload());
    }

    public function updateDelivery(Request $request)
    {
        $validated = $request->validate([
            'base_km' => ['required', 'numeric', 'min:0', 'max:100'],
            'base_fee' => ['required', 'numeric', 'min:0', 'max:10000'],
            'extra_fee_per_km' => ['required', 'numeric', 'min:0', 'max:10000'],
        ]);

        $actor = (int) $request->user()->getKey();

        Setting::put(Setting::DELIVERY_BASE_KM, round((float) $validated['base_km'], 2), $actor);
        Setting::put(Setting::DELIVERY_BASE_FEE, round((float) $validated['base_fee'], 2), $actor);
        Setting::put(Setting::DELIVERY_EXTRA_FEE_PER_KM, round((float) $validated['extra_fee_per_km'], 2), $actor);

        return response()->json([
            'success' => true,
            'message' => 'Delivery pricing updated.',
        ] + $this->deliveryPayload());
    }

    public function showCancellation()
    {
        return response()->json($this->cancellationPayload());
    }
    
    public function updateCancellation(Request $request)
    {
        $validated = $request->validate([
            'full_refund_through' => ['required', 'string', Rule::in(CancellationPolicy::thresholds())],
            'advance_cutoff_hours' => ['required', 'integer', 'min:0', 'max:720'],
        ], [
            'full_refund_through.in' => 'A refund window cannot extend past the kitchen starting.',
        ]);

        $actor = (int) $request->user()->getKey();

        Setting::put(Setting::CANCELLATION_FULL_REFUND_THROUGH, $validated['full_refund_through'], $actor);
        Setting::put(Setting::CANCELLATION_ADVANCE_CUTOFF_HOURS, (int) $validated['advance_cutoff_hours'], $actor);

        return response()->json([
            'success' => true,
            'message' => 'Cancellation and refund rules updated.',
        ] + $this->cancellationPayload());
    }

    public function showLoyalty()
    {
        return response()->json($this->loyaltyPayload());
    }

    public function updateLoyalty(Request $request)
    {
        $validated = $request->validate([
            'points_per_peso' => ['required', 'numeric', 'min:0', 'max:100'],
            'peso_per_point' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $actor = (int) $request->user()->getKey();

        Setting::put(Setting::LOYALTY_POINTS_PER_PESO, round((float) $validated['points_per_peso'], 4), $actor);
        Setting::put(Setting::LOYALTY_PESO_PER_POINT, round((float) $validated['peso_per_point'], 4), $actor);

        return response()->json([
            'success' => true,
            'message' => 'Loyalty rates updated.',
        ] + $this->loyaltyPayload());
    }

    public function showStore()
    {
        return response()->json($this->storePayload());
    }

    public function updateStore(Request $request)
    {
        $validated = $request->validate([
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'different:opens_at'],
        ], [
            'closes_at.different' => 'Opening and closing time cannot be the same.',
        ]);

        $actor = (int) $request->user()->getKey();

        Setting::put(Setting::STORE_OPENS_AT, $validated['opens_at'], $actor);
        Setting::put(Setting::STORE_CLOSES_AT, $validated['closes_at'], $actor);

        return response()->json([
            'success' => true,
            'message' => 'Operating hours updated.',
        ] + $this->storePayload());
    }

    public function updateStoreToggles(Request $request)
    {
        $validated = $request->validate([
            'ordering_override' => ['sometimes', Rule::in(StoreAvailability::overrides())],
            'delivery_enabled' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json([
                'success' => false,
                'message' => 'Nothing to change.',
            ], 422);
        }

        $actor = (int) $request->user()->getKey();

        if (array_key_exists('ordering_override', $validated)) {
            Setting::put(Setting::STORE_ORDERING_OVERRIDE, $validated['ordering_override'], $actor);
        }

        if (array_key_exists('delivery_enabled', $validated)) {
            Setting::put(Setting::STORE_DELIVERY_ENABLED, (bool) $validated['delivery_enabled'], $actor);
        }

        return response()->json([
            'success' => true,
            'message' => 'Store availability updated.',
        ] + $this->storePayload());
    }

    private function announceRateChange(float $previous, float $next): void
    {
        $title = 'Discount rate updated';
        $body = 'The Senior Citizen and PWD discount is now '.$this->format($next)
            .'%, changed from '.$this->format($previous).'%.';

        try {
            User::query()
                ->select('id')
                ->chunkById(500, function ($users) use ($title, $body) {
                    foreach ($users as $user) {
                        $notification = Notification::create([
                            'user_id' => $user->id,
                            'title' => $title,
                            'body' => $body,
                            'is_read' => false,
                        ]);

                        try {
                            NotificationBroadcast::dispatch($notification, (int) $user->id);
                        } catch (Throwable $e) {
                            Log::warning('Could not broadcast a discount rate change.', [
                                'user_id' => $user->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        } catch (Throwable $e) {
            Log::warning('Could not announce the discount rate change.', ['error' => $e->getMessage()]);
        }
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** @return array<string, mixed> */
    private function discountPayload(): array
    {
        $setting = Setting::query()
            ->with('updatedBy:id,first_name,last_name')
            ->where('key', Setting::DISCOUNT_PERCENTAGE)
            ->first();

        return [
            'percentage' => Discount::currentPercentage(),
            'minimum_percentage' => Discount::MINIMUM_PERCENTAGE,
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->updatedBy,
        ];
    }

    /** @return array<string, mixed> */
    private function cancellationPayload(): array
    {
        $policy = app(CancellationPolicy::class);

        return [
            'full_refund_through' => $policy->fullRefundThrough(),
            'advance_cutoff_hours' => $policy->advanceCutoffHours(),
            'options' => array_map(fn (string $status) => [
                'value' => $status,
                'label' => OrderStatus::label($status),
            ], CancellationPolicy::thresholds()),
            'defaults' => [
                'full_refund_through' => CancellationPolicy::DEFAULT_FULL_REFUND_THROUGH,
                'advance_cutoff_hours' => CancellationPolicy::DEFAULT_ADVANCE_CUTOFF_HOURS,
            ],
        ] + $this->provenance(Setting::CANCELLATION_FULL_REFUND_THROUGH);
    }

    private function deliveryPayload(): array
    {
        $pricing = app(DeliveryPricing::class);

        return [
            'base_km' => $pricing->baseKm(),
            'base_fee' => $pricing->baseFee(),
            'extra_fee_per_km' => $pricing->extraFeePerKm(),
            'defaults' => [
                'base_km' => DeliveryPricing::DEFAULT_BASE_KM,
                'base_fee' => DeliveryPricing::DEFAULT_BASE_FEE,
                'extra_fee_per_km' => DeliveryPricing::DEFAULT_EXTRA_FEE_PER_KM,
            ],
        ] + $this->provenance(Setting::DELIVERY_BASE_FEE);
    }

    /** @return array<string, mixed> */
    private function loyaltyPayload(): array
    {
        return [
            'points_per_peso' => Setting::number(Setting::LOYALTY_POINTS_PER_PESO, 0.0),
            'peso_per_point' => Setting::number(Setting::LOYALTY_PESO_PER_POINT, 0.0),
            'active' => false,
        ] + $this->provenance(Setting::LOYALTY_POINTS_PER_PESO);
    }

    /** @return array<string, mixed> */
    private function storePayload(): array
    {
        $store = app(StoreAvailability::class);

        return [
            'status' => $store->snapshot(),
            'defaults' => [
                'opens_at' => (string) config('store.hours.opens_at'),
                'closes_at' => (string) config('store.hours.closes_at'),
            ],
            'max_driving_km' => (float) config('store.max_driving_km'),
        ] + $this->provenance(Setting::STORE_OPENS_AT);
    }

    private function provenance(string $key): array
    {
        $setting = Setting::query()
            ->with('updatedBy:id,first_name,last_name')
            ->where('key', $key)
            ->first();

        return [
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->updatedBy,
        ];
    }
}
