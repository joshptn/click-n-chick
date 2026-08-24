<?php

namespace App\Services\Orders;

use App\Services\Geo\RouteNotFound;
use App\Services\Geo\RoutingProvider;
use App\Services\Geo\RoutingUnavailable;
use App\Utils\Distance;

class DeliveryQuote
{
    public function __construct(
        private DeliveryPricing $pricing,
        private RoutingProvider $routing,
    ) {}

    public function maxDrivingKm(): float
    {
        return (float) config('store.max_driving_km', 45.0);
    }

    public function origin(): array
    {
        return [
            'latitude' => (float) config('store.origin.latitude'),
            'longitude' => (float) config('store.origin.longitude'),
        ];
    }

    public function straightLineKm(float $latitude, float $longitude): float
    {
        return round(Distance::getDistance($latitude, $longitude), 2);
    }

    public function possiblyInRange(float $latitude, float $longitude): bool
    {
        return $this->straightLineKm($latitude, $longitude) <= $this->maxDrivingKm();
    }

    public function for(float $latitude, float $longitude): array
    {
        $limit = $this->maxDrivingKm();
        $straightLine = $this->straightLineKm($latitude, $longitude);

        $base = [
            'straight_line_km' => $straightLine,
            'max_driving_km' => $limit,
            'origin' => $this->origin(),
            'pricing' => [
                'base_km' => $this->pricing->baseKm(),
                'base_fee' => $this->pricing->baseFee(),
                'extra_fee_per_km' => $this->pricing->extraFeePerKm(),
            ],
        ];

        if ($straightLine > $limit) {
            return $base + [
                'distance_km' => null,
                'geometry' => null,
                'within_service_area' => false,
                'routing_available' => true,
                'route_found' => null,
                'fee' => null,
                'message' => 'That address is about '.$this->format($straightLine)
                    .' km away in a straight line, which is beyond our '
                    .$this->format($limit).' km delivery range. You can still place a pickup order.',
            ];
        }

        try {
            $route = $this->routing->route($latitude, $longitude);
        } catch (RouteNotFound $e) {
            return $base + [
                'distance_km' => null,
                'geometry' => null,
                'within_service_area' => false,
                'routing_available' => true,
                'route_found' => false,
                'fee' => null,
                'message' => 'We could not find a road to that exact spot. '
                    .'Move the pin closer to a street and try again.',
            ];
        } catch (RoutingUnavailable $e) {
            return $base + [
                'distance_km' => null,
                'geometry' => null,
                'within_service_area' => false,
                'routing_available' => false,
                'route_found' => null,
                'fee' => null,
                'message' => 'We cannot work out the delivery distance right now. '
                    .'Please try again shortly, or place a pickup order.',
            ];
        }

        $drivingKm = $route->distanceKm;
        $geometry = $route->hasGeometry() ? $route->geometry : null;

        if ($drivingKm > $limit) {
            return $base + [
                'distance_km' => $drivingKm,
                'geometry' => $geometry,
                'within_service_area' => false,
                'routing_available' => true,
                'route_found' => true,
                'fee' => null,
                'message' => 'That address is '.$this->format($drivingKm)
                    .' km away by road, outside our '.$this->format($limit)
                    .' km delivery range. You can still place a pickup order.',
            ];
        }

        return $base + [
            'distance_km' => $drivingKm,
            'geometry' => $geometry,
            'within_service_area' => true,
            'routing_available' => true,
            'route_found' => true,
            'fee' => $this->pricing->feeFor($drivingKm),
            'message' => null,
        ];
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
