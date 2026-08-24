<?php

namespace App\Services\Geo;

/**
 * A driving route from the shop to a destination.
 *
 * An interface rather than a concrete class because the hosting decision is
 * expected to change: OpenRouteService's hosted tier today, very likely a
 * self-hosted instance later. Self-hosted ORS is a base-URL change, but OSRM
 * speaks a different API entirely, and callers should not have to know which
 * is answering.
 */
interface RoutingProvider
{
    /**
     * The road distance, and the line it follows.
     *
     * Never returns an estimate or a fallback. A provider that cannot answer
     * throws, so the caller decides what an unanswerable route means - which
     * for checkout is "delivery is briefly unavailable", never "guess".
     *
     * @throws RoutingUnavailable
     */
    public function route(float $latitude, float $longitude): Route;
}
