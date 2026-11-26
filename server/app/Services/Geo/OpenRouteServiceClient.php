<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenRouteService's hosted API: the fallback while OSRM is down
 * (FallbackRoutingClient, decided 2026-11-26).
 *
 * ORS treats roads tagged access=private as closed, and most subdivisions
 * around the shop are tagged that way, so its routes stop at the gate. That
 * is why OSRM answers first.
 */
class OpenRouteServiceClient extends CachedRoutingClient
{
    public function __construct(
        private string $endpoint,
        private ?string $apiKey,
        private string $profile,
    ) {}

    protected function name(): string
    {
        return 'OpenRouteService';
    }

    /** The profile alone, as before OSRM, so existing cache entries still match. */
    protected function cacheNamespace(): string
    {
        return $this->profile;
    }

    protected function configured(): bool
    {
        return filled($this->apiKey);
    }

    protected function fetch(float $latitude, float $longitude): Route
    {
        $origin = $this->origin();

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('services.routing.timeout', 6))
                ->connectTimeout((int) config('services.routing.connect_timeout', 3))
                ->post($this->routeUrl(), [
                    'coordinates' => [
                        [$origin['longitude'], $origin['latitude']],
                        [$longitude, $latitude],
                    ],
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

    private function routeUrl(): string
    {
        return rtrim($this->endpoint, '/').'/v2/directions/'.$this->profile;
    }
}
