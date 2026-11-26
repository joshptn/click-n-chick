<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Log;

/**
 * The routing engines in order (decided 2026-11-26): OSRM, then
 * OpenRouteService if OSRM is down.
 *
 * Only an outage moves on to the next engine. "No route to that pin" is an
 * answer, not an outage: the pin is somewhere a car cannot reach, and asking
 * ORS, which reaches fewer places, would not change that.
 */
final class FallbackRoutingClient implements RoutingProvider
{
    /** @param  list<RoutingProvider>  $engines */
    public function __construct(private array $engines) {}

    public function route(float $latitude, float $longitude): Route
    {
        $outage = null;

        foreach ($this->engines as $index => $engine) {
            try {
                return $engine->route($latitude, $longitude);
            } catch (RouteNotFound $e) {
                throw $e;
            } catch (RoutingUnavailable $e) {
                $outage = $e;

                if ($index < count($this->engines) - 1) {
                    Log::info('A routing engine is unavailable; trying the next.', ['engine' => $engine::class]);
                }
            }
        }

        throw $outage ?? new RoutingUnavailable('The delivery distance service is not configured.');
    }
}
