<?php

namespace App\Services\Orders;

use App\Utils\Distance;

/**
 * Distance, service-area verdict, and fee for one destination.
 *
 * The single place any of those three are decided. The browser draws the map
 * and picks the pin; it never computes what that pin costs. A fee quoted by
 * the client would be a fee the customer could edit.
 *
 * Distance is straight-line Haversine (UC-DEL-004/005), computed here rather
 * than fetched:
 *
 *   - it is instant and free, so it can be recomputed on every pin drag and
 *     again at place-order without a rate limit or an outage in the way;
 *   - it is deterministic, which is what the "consistent delivery fee" success
 *     metric asks for - a routing service can return a different distance for
 *     the same two points after a road closure, and with it a different fee;
 *   - it never exceeds road distance, so the fee errs toward the customer.
 */
class DeliveryQuote
{
    public function __construct(private DeliveryPricing $pricing) {}

    public function radiusKm(): float
    {
        return (float) config('store.service_radius_km', 45.0);
    }

    /** @return array{latitude: float, longitude: float} */
    public function origin(): array
    {
        return [
            'latitude' => (float) config('store.origin.latitude'),
            'longitude' => (float) config('store.origin.longitude'),
        ];
    }

    public function distanceKm(float $latitude, float $longitude): float
    {
        return round(Distance::getDistance($latitude, $longitude), 2);
    }

    public function withinServiceArea(float $latitude, float $longitude): bool
    {
        return $this->distanceKm($latitude, $longitude) <= $this->radiusKm();
    }

    /**
     * The full verdict for a destination.
     *
     * Out-of-area destinations report a null fee rather than a computed one:
     * there is no price for a delivery that will not happen, and returning a
     * number invites the UI to display it.
     *
     * @return array<string, mixed>
     */
    public function for(float $latitude, float $longitude): array
    {
        $distance = $this->distanceKm($latitude, $longitude);
        $radius = $this->radiusKm();
        $within = $distance <= $radius;

        return [
            'distance_km' => $distance,
            'radius_km' => $radius,
            'within_service_area' => $within,
            'fee' => $within ? $this->pricing->feeFor($distance) : null,
            'origin' => $this->origin(),
            'pricing' => [
                'base_km' => $this->pricing->baseKm(),
                'base_fee' => $this->pricing->baseFee(),
                'extra_fee_per_km' => $this->pricing->extraFeePerKm(),
            ],
            'message' => $within
                ? null
                : 'That address is '.$this->format($distance).' km away, outside our '
                    .$this->format($radius).' km delivery area. You can still place a pickup order.',
        ];
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
