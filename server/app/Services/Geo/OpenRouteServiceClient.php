<?php

namespace App\Services\Geo;

use App\Utils\Distance;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenRouteServiceClient implements RoutingProvider
{
    private const SNAP_TOLERANCE_KM = 1.0;

    private const LOCK_TTL_SECONDS = 15;

    public function __construct(
        private string $endpoint,
        private ?string $apiKey,
        private string $profile,
    ) {}

    public function route(float $latitude, float $longitude): Route
    {
        if (blank($this->apiKey)) {
            Log::warning('Routing is enabled but no OpenRouteService key is configured.');

            throw new RoutingUnavailable('The delivery distance service is not configured.');
        }

        $key = $this->cacheKey($latitude, $longitude);

        if ($hit = $this->cached($key)) {
            return $hit;
        }

        $lock = Cache::lock('lock:'.$key, self::LOCK_TTL_SECONDS);

        if (! $this->waitFor($lock)) {
            return $this->fetchAndStore($key, $latitude, $longitude);
        }

        try {
            return $this->cached($key) ?? $this->fetchAndStore($key, $latitude, $longitude);
        } finally {
            $lock->release();
        }
    }

    private function cached(string $key): ?Route
    {
        $cached = Cache::get($key);

        return is_array($cached) && isset($cached['distance'])
            ? new Route((float) $cached['distance'], $cached['geometry'] ?? null)
            : null;
    }

    private function fetchAndStore(string $key, float $latitude, float $longitude): Route
    {
        $route = $this->fetch($latitude, $longitude);

        Cache::put(
            $key,
            ['distance' => $route->distanceKm, 'geometry' => $route->geometry],
            (int) config('services.routing.cache_ttl', 604800),
        );

        return $route;
    }

    private function waitFor(Lock $lock): bool
    {
        try {
            return $lock->block((int) config('services.routing.lock_wait', 3));
        } catch (Throwable) {
            return false;
        }
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
                    'coordinates' => [$origin, [$longitude, $latitude]],
                    'units' => 'km',
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
            throw new RouteNotFound('No driving route could be found to that location.');
        }

        $this->assertPlausible($distance, $latitude, $longitude);

        return new Route($distance, $this->readGeometry($body));
    }

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

    private function cacheKey(float $latitude, float $longitude): string
    {
        return 'route:'.$this->profile.':'
            .number_format($latitude, 4, '.', '').','
            .number_format($longitude, 4, '.', '');
    }
}
