<?php

namespace App\Http\Controllers;

use App\Events\NotificationBroadcast;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\DeliveryPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    /**
     * Who last changed a setting, and when.
     *
     * Takes one representative key for groups written together - they are
     * always saved in the same request, so their provenance is identical.
     *
     * @return array<string, mixed>
     */
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
