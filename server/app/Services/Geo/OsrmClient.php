<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OSRM through a hosted, OSRM-compatible API (decided 2026-11-26): the public
 * server by default, or a managed host such as osrmroute.com with its key.
 * Either is only a base URL and an optional key: everything after /route/v1
 * is OSRM's own API.
 *
 * Chosen over OpenRouteService because OSRM's car profile lets a route start
 * or end on a road tagged access=private, which is how OpenStreetMap tags the
 * subdivisions around the shop: OSRM drives in to the customer's street while
 * ORS stopped at the gate. It still never cuts through a subdivision on the
 * way somewhere else. ORS stays as the fallback when OSRM is down
 * (FallbackRoutingClient).
 */
class OsrmClient extends CachedRoutingClient
{
    /**
     * "No road there" and "no way to get there": OSRM's own codes, and
     * osrmroute.com's for a pin with no connected road within 2 km.
     */
    private const NOT_FOUND_CODES = ['NoSegment', 'NoRoute', 'NoNearbyRoad'];

    public function __construct(
        private ?string $endpoint,
        private ?string $token,
        private string $profile,
        private int $snapMetres,
    ) {}

    protected function name(): string
    {
        return 'OSRM';
    }

    protected function cacheNamespace(): string
    {
        return 'osrm-'.$this->profile;
    }

    protected function configured(): bool
    {
        return filled($this->endpoint);
    }

    protected function fetch(float $latitude, float $longitude): Route
    {
        $request = Http::acceptJson()
            ->withUserAgent((string) config('services.routing.user_agent', 'delivery-quote/1.0'))
            ->timeout((int) config('services.routing.timeout', 6))
            ->connectTimeout((int) config('services.routing.connect_timeout', 3));

        // A managed host's API key; the public server takes none.
        if (filled($this->token)) {
            $request = $request->withToken($this->token);
        }

        try {
            $response = $request->get($this->routeUrl($latitude, $longitude));
        } catch (ConnectionException $e) {
            Log::warning('OSRM unreachable.', ['error' => $e->getMessage()]);

            throw new RoutingUnavailable('The delivery distance service is not responding.', previous: $e);
        }

        $body = $response->json();
        $code = is_array($body) ? ($body['code'] ?? null) : null;

        // Answered with a 400 or 422, so they come before the status checks.
        if (in_array($code, self::NOT_FOUND_CODES, true)) {
            throw new RouteNotFound('No driving route could be found to that location.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            Log::error('The OSRM host rejected our API key.', ['status' => $response->status()]);

            throw new RoutingUnavailable('The delivery distance service refused the request.');
        }

        if ($response->status() === 429) {
            Log::warning('The OSRM host is rate limiting us.');

            throw new RoutingUnavailable('The delivery distance service is busy.');
        }

        if ($response->failed() || $code !== 'Ok') {
            Log::warning('OSRM returned an error.', ['status' => $response->status(), 'code' => $code]);

            throw new RoutingUnavailable('The delivery distance service returned an error.');
        }

        $metres = $body['routes'][0]['distance'] ?? null;

        if (! is_numeric($metres)) {
            throw new RouteNotFound('No driving route could be found to that location.');
        }

        // How far OSRM moved the pin to reach a road. Held here rather than
        // with OSRM's radiuses option, which not every host passes on: a pin
        // in the fishponds must not be priced from a road a kilometre away.
        $snapped = $body['waypoints'][1]['distance'] ?? null;

        if (is_numeric($snapped) && (float) $snapped > max(1, $this->snapMetres)) {
            throw new RouteNotFound('No driving route could be found to that location.');
        }

        return new Route(round((float) $metres / 1000, 2), $this->readGeometry($body));
    }

    /**
     * /route/v1/{profile}/{shop};{pin}, longitude first. Built by hand: the
     * semicolon must reach OSRM unencoded.
     *
     * overview=full: the simplified line has a handful of points and cuts
     * across blocks inside a subdivision; the full one follows the streets
     * and is still only a few hundred points at 45 km.
     */
    private function routeUrl(float $latitude, float $longitude): string
    {
        $origin = $this->origin();

        return rtrim((string) $this->endpoint, '/').'/route/v1/'.$this->profile.'/'
            .$this->pair($origin['latitude'], $origin['longitude']).';'
            .$this->pair($latitude, $longitude)
            .'?overview=full&geometries=polyline&alternatives=false&steps=false';
    }

    private function pair(float $latitude, float $longitude): string
    {
        return number_format($longitude, 6, '.', '').','.number_format($latitude, 6, '.', '');
    }

    /** A precision-5 polyline, the same encoding ORS sends. */
    private function readGeometry(array $body): ?array
    {
        $encoded = $body['routes'][0]['geometry'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $points = EncodedPolyline::decode($encoded);

        return count($points) >= 2 ? $points : null;
    }
}
