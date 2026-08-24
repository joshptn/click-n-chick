<?php

namespace App\Services\Geo;

/**
 * One driving route: how far, and the line it follows.
 *
 * The two travel together because they come from the same request. Asking for
 * the distance and then the shape would double the calls against a daily
 * quota to draw a line the first call already returned.
 *
 * `distanceKm` is load-bearing - it sets the fee and decides the service area.
 * `geometry` is decoration: a route with no drawable shape is still a valid,
 * chargeable delivery, so it is nullable and nothing may depend on it.
 */
class Route
{
    /**
     * @param  array<int, array{0: float, 1: float}>|null  $geometry  [lat, lng] pairs, in the order driven
     */
    public function __construct(
        public readonly float $distanceKm,
        public readonly ?array $geometry = null,
    ) {}

    public function hasGeometry(): bool
    {
        return is_array($this->geometry) && count($this->geometry) >= 2;
    }
}
