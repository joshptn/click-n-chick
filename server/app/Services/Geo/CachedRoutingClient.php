<?php

namespace App\Services\Geo;

use App\Utils\Distance;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What every routing engine shares: the cache, the lock and the sanity check.
 *
 * Each engine only has to fetch one route. The answer is cached by coordinate
 * at 4 decimals (about 11 m). The fee is banded by whole kilometres, so a
 * coarser key would move some pins a full band (see DeliveryPricing). The
 * namespace keeps one engine's distances from ever pricing another's.
 */
abstract class CachedRoutingClient implements RoutingProvider
{
    private const SNAP_TOLERANCE_KM = 1.0;

    private const LOCK_TTL_SECONDS = 15;

    /** The engine's name, for the logs. */
    abstract protected function name(): string;

    /** Distinct per engine and profile. */
    abstract protected function cacheNamespace(): string;

    abstract protected function configured(): bool;

    /**
     * One route from the shop to this point, straight from the engine.
     *
     * @throws RoutingUnavailable
     */
    abstract protected function fetch(float $latitude, float $longitude): Route;

    public function route(float $latitude, float $longitude): Route
    {
        if (! $this->configured()) {
            Log::warning('Routing is enabled but '.$this->name().' is not configured.');

            throw new RoutingUnavailable('The delivery distance service is not configured.');
        }

        $key = $this->cacheKey($latitude, $longitude);

        if ($hit = $this->cached($key)) {
            return $hit;
        }

        $lock = Cache::lock('lock:'.$key, self::LOCK_TTL_SECONDS);

        // Giving up on the lock still routes: it only saves duplicate calls,
        // and must never turn a routable address into a refusal.
        if (! $this->waitFor($lock)) {
            return $this->fetchAndStore($key, $latitude, $longitude);
        }

        try {
            return $this->cached($key) ?? $this->fetchAndStore($key, $latitude, $longitude);
        } finally {
            $lock->release();
        }
    }

    /** The shop, as configured. */
    protected function origin(): array
    {
        return [
            'latitude' => (float) config('store.origin.latitude'),
            'longitude' => (float) config('store.origin.longitude'),
        ];
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

        $this->assertPlausible($route->distanceKm, $latitude, $longitude);

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

    /**
     * Driving distance shorter than the straight line is impossible (swapped
     * coordinates, a units mix-up), so the route is refused rather than
     * clamped. The tolerance allows for both ends being snapped to a road.
     */
    private function assertPlausible(float $distance, float $latitude, float $longitude): void
    {
        $straightLine = Distance::getDistance($latitude, $longitude);
        $tolerance = max(self::SNAP_TOLERANCE_KM, $straightLine * 0.10);

        if ($distance < $straightLine - $tolerance) {
            Log::error($this->name().' returned an implausibly short distance.', [
                'driving_km' => $distance,
                'straight_line_km' => round($straightLine, 3),
                'tolerance_km' => round($tolerance, 3),
            ]);

            throw new RoutingUnavailable('The delivery distance could not be verified.');
        }
    }

    private function cacheKey(float $latitude, float $longitude): string
    {
        return 'route:'.$this->cacheNamespace().':'
            .number_format($latitude, 4, '.', '').','
            .number_format($longitude, 4, '.', '');
    }
}
