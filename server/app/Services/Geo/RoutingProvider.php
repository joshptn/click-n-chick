<?php

namespace App\Services\Geo;

/**
 * A driving route from the shop to a destination.
 *
 * An interface rather than a concrete class because more than one engine
 * answers: OSRM first and OpenRouteService while it is down
 * (FallbackRoutingClient). They speak different APIs, and callers should not
 * have to know which one answered.
 */
interface RoutingProvider
{
    /**
     * @throws RoutingUnavailable
     */
    public function route(float $latitude, float $longitude): Route;
}
