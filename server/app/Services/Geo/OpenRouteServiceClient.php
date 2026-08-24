<?php

namespace App\Services\Geo;

use App\Utils\Distance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouteServiceClient implements DrivingDistanceProvider
{
    public function __construct(
        private string $endpoint,
        private ?string $apiKey,
        private string $profile,
    ) {}

    public function drivingDistanceKm(float $latitude, float $longitude): float
    {
        if (blank($this->apiKey)) {
            Log::warning('Routing is enabled but no OpenRouteService key is configured.');

            throw new RoutingUnavailable('The delivery distance service is not configured.');
        }

        $key = $this->cacheKey($latitude, $longitude);
        $cached = Cache::get($key);

        if ($cached !== null) {
            return (float) $cached;
        }

        $distance = $this->fetch($latitude, $longitude);

        Cache::put($key, $distance, (int) config('services.routing.cache_ttl', 604800));

        return $distance;
    }

    private function fetch(float $latitude, float $longitude): float
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

        if ($response->failed()) {
            Log::warning('OpenRouteService returned an error.', ['status' => $response->status()]);

            throw new RoutingUnavailable('The delivery distance service returned an error.');
        }

        $distance = $this->readDistance($response->json());

        if ($distance === null) {
            throw new RoutingUnavailable('No driving route could be found to that location.');
        }

        $this->assertPlausible($distance, $latitude, $longitude);

        return $distance;
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

    private const SNAP_TOLERANCE_KM = 1.0;

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
