<?php

namespace App\Services\Orders;

use App\Models\Setting;

class DeliveryPricing
{
    public const DEFAULT_BASE_KM = 3.0;

    public const DEFAULT_BASE_FEE = 55.0;

    public const DEFAULT_EXTRA_FEE_PER_KM = 10.0;

    public function baseKm(): float
    {
        return max(0.0, Setting::number(Setting::DELIVERY_BASE_KM, self::DEFAULT_BASE_KM));
    }

    public function baseFee(): float
    {
        return max(0.0, Setting::number(Setting::DELIVERY_BASE_FEE, self::DEFAULT_BASE_FEE));
    }

    public function extraFeePerKm(): float
    {
        return max(0.0, Setting::number(Setting::DELIVERY_EXTRA_FEE_PER_KM, self::DEFAULT_EXTRA_FEE_PER_KM));
    }

    /** The service area, by road: the Store Manager's figure, else the configured one. */
    public function maxDrivingKm(): float
    {
        return max(0.0, Setting::number(Setting::DELIVERY_MAX_KM, (float) config('store.max_driving_km', 45.0)));
    }

    public function feeFor(float $km): float
    {
        $baseKm = $this->baseKm();

        if ($km <= $baseKm) {
            return round($this->baseFee(), 2);
        }

        $extraKm = ceil($km - $baseKm);

        return round($this->baseFee() + ($extraKm * $this->extraFeePerKm()), 2);
    }
}
