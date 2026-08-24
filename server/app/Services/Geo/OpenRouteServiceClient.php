<?php

namespace App\Services\Geo;

use App\Utils\Distance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driving routes from OpenRouteService.
 *
 * The hosted free tier, which suits this shop's shape of demand: 10-15
 * delivery orders on a normal day against a 2,000/day quota, and delivery is
 * switched off entirely during MCGI surges - so the busiest hours are exactly
 * the hours this is not consulted.
 *
 * Distance and geometry come from one request and are cached together. Asking
 * separately would double the quota cost to draw a line the first answer
 * already contained.
 */
class OpenRouteServiceClient implements RoutingProvider
{
    private const SNAP_TOLERANCE_KM = 1.0;

    public function __construct(
        private string $endpoint,
        private ?string $apiKey,
        private string $profile,
    ) {}

    public function route(float $latitude, float $longitude): Route
    {
        if (blank($this->apiKey)) {
            // Misconfiguration, not an outage - but the caller's handling is
            // the same, and checkout must not leak the difference.
            Log::warning('Routing is enabled but no OpenRouteService key is configured.');

            throw new RoutingUnavailable('The delivery distance service is not configured.');
        }

        $key = $this->cacheKey($latitude, $longitude);
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['distance'])) {
            return new Route((float) $cached['distance'], $cached['geometry'] ?? null);
        }

        $route = $this->fetch($latitude, $longitude);

        Cache::put(
            $key,
            ['distance' => $route->distanceKm, 'geometry' => $route->geometry],
            (int) config('services.routing.cache_ttl', 604800),
        );

        return $route;
    }

    private function fetch(float $latitude, float $longitude): Route
    {
        $origin = [
            (float) config('store.origin.longitude'),
            (float) config('store.origin.latitude'),
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('services.routing.timeout', 6))
                ->connectTimeout((int) config('services.routing.connect_timeout', 3))
                ->post($this->routeUrl(), [
                    // ORS takes [longitude, latitude]. The reverse of almost
                    // every other API in this codebase, and silently returns a
                    // route somewhere else entirely if you get it wrong.
                    'coordinates' => [$origin, [$longitude, $latitude]],
                    'units' => 'km',
                    // Turn-by-turn text is not wanted; the shape is, and
                    // simplified is plenty for a line a few hundred pixels wide.
                    'instructions' => false,
                    'geometry_simplify' => true,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('OpenRouteService unreachable.', ['error' => $e->getMessage()]);

            throw new RoutingUnavailable('The delivery distance service is not responding.', previous: $e);
        }

        if ($response->status() === 429) {
            Log::warning('OpenRouteService quota exhausted.');

            throw new RoutingUnavailable('The delivery distance service is busy.');
        }

        if ($response->status() === 403 || $response->status() === 401) {
            Log::error('OpenRouteService rejected our credentials.', ['status' => $response->status()]);

            throw new RoutingUnavailable('The delivery distance service refused the request.');
        }

        // 404 is ORS answering, not ORS failing: it looked and there is no
        // road route to that point. Distinct from an outage because retrying
        // cannot help - only moving the pin can.
        if ($response->status() === 404) {
            throw new RouteNotFound('No driving route could be found to that location.');
        }

        if ($response->failed()) {
            Log::warning('OpenRouteService returned an error.', ['status' => $response->status()]);

            throw new RoutingUnavailable('The delivery distance service returned an error.');
        }

        $body = $response->json();
        $distance = $this->readDistance($body);

        if ($distance === null) {
            // A 200 that carries no route - most often a destination with no
            // road connection to the origin, such as a pin dropped in a
            // fishpond, which around Apalit is easy to do.
            throw new RouteNotFound('No driving route could be found to that location.');
        }

        $this->assertPlausible($distance, $latitude, $longitude);

        return new Route($distance, $this->readGeometry($body));
    }

    /**
     * ORS answers in one of two shapes depending on the endpoint used.
     *
     * `/directions/{profile}` returns `routes[].summary.distance`, while the
     * GeoJSON variant returns `features[].properties.summary.distance`. Both
     * are read so a future endpoint change does not silently return null.
     */
    private function readDistance(mixed $body): ?float
    {
        if (! is_array($body)) {
            return null;
        }

        $summary = $body['routes'][0]['summary']
            ?? $body['features'][0]['properties']['summary']
            ?? null;

        if (! is_array($summary) || ! isset($summary['distance'])) {
            return null;
        }

        return round((float) $summary['distance'], 2);
    }

    /**
     * The line the route follows, or null.
     *
     * Decoration, and treated as such: a missing or unreadable geometry costs
     * the customer a drawn line, never a delivery. Anything unexpected here
     * returns null rather than throwing.
     *
     * Handles both response shapes - an encoded polyline from the JSON
     * endpoint, or a GeoJSON LineString (which stores [lng, lat], the reverse
     * of what Leaflet draws).
     *
     * @return array<int, array{0: float, 1: float}>|null
     */
    private function readGeometry(mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        $encoded = $body['routes'][0]['geometry'] ?? null;

        if (is_string($encoded) && $encoded !== '') {
            $points = EncodedPolyline::decode($encoded);

            return count($points) >= 2 ? $points : null;
        }

        $line = $body['features'][0]['geometry']['coordinates'] ?? null;

        if (! is_array($line) || count($line) < 2) {
            return null;
        }

        $points = [];

        foreach ($line as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1])) {
                $points[] = [round((float) $pair[1], 6), round((float) $pair[0], 6)];
            }
        }

        return count($points) >= 2 ? $points : null;
    }

    /**
     * A sanity check, not a correction.
     *
     * Driving distance between two points cannot be shorter than the straight
     * line between them, so an answer that is catches gross faults: swapped
     * coordinate order, a units mix-up, a truncated response.
     *
     * The tolerance is not slop. A router snaps both endpoints onto the
     * nearest road before measuring, and the snapped pair can genuinely sit
     * closer together than the coordinates asked about - a pin 405 m from the
     * shop legitimately routed as 360 m during testing, because both ends
     * moved onto the highway. Snapping can shift an endpoint by a few hundred
     * metres, so anything inside a kilometre is expected rather than suspect.
     *
     * The faults this exists to catch are wrong by kilometres or by orders of
     * magnitude, and a proportional term keeps catching those at long range.
     *
     * Deliberately NOT clamped to the straight-line value: substituting one
     * would quietly promote Haversine into the authoritative distance, which
     * is precisely what it is not.
     */
    private function assertPlausible(float $distance, float $latitude, float $longitude): void
    {
        $straightLine = Distance::getDistance($latitude, $longitude);
        $tolerance = max(self::SNAP_TOLERANCE_KM, $straightLine * 0.10);

        if ($distance < $straightLine - $tolerance) {
            Log::error('OpenRouteService returned an implausibly short distance.', [
                'driving_km' => $distance,
                'straight_line_km' => round($straightLine, 3),
                'tolerance_km' => round($tolerance, 3),
            ]);

            throw new RoutingUnavailable('The delivery distance could not be verified.');
        }
    }

    private function routeUrl(): string
    {
        return rtrim($this->endpoint, '/').'/v2/directions/'.$this->profile;
    }

    /**
     * ~11 m of precision, which is finer than a doorway.
     *
     * The origin is fixed, so the destination alone identifies the route. The
     * profile is in the key because changing it changes the answer.
     */
    private function cacheKey(float $latitude, float $longitude): string
    {
        return 'route:'.$this->profile.':'
            .number_format($latitude, 4, '.', '').','
            .number_format($longitude, 4, '.', '');
    }
}
