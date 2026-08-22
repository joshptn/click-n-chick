<?php

namespace App\Utils;

class Distance
{
    private const EARTH_RADIUS_KM = 6371;

    public static function getDistance($lat, $lng): float
    {
        return self::between(
            (float) config('store.origin.latitude'),
            (float) config('store.origin.longitude'),
            (float) $lat,
            (float) $lng,
        );
    }

    /** Haversine. Straight-line, not road distance - see DeliveryQuote. */
    public static function between(float $latFrom, float $lngFrom, float $latTo, float $lngTo): float
    {
        $latFrom = deg2rad($latFrom);
        $lngFrom = deg2rad($lngFrom);
        $latTo = deg2rad($latTo);
        $lngTo = deg2rad($lngTo);

        $latDelta = $latTo - $latFrom;
        $lngDelta = $lngTo - $lngFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos($latFrom) * cos($latTo) *
             sin($lngDelta / 2) * sin($lngDelta / 2);

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
