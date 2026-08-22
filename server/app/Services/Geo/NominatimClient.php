<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenStreetMap Nominatim, kept behind the API.
 *
 * The browser never talks to nominatim.openstreetmap.org directly, for three
 * reasons that each independently rule it out:
 *
 *   - The usage policy requires an identifying User-Agent. `fetch()` cannot
 *     set that header; the browser overwrites it. Calling from the client is
 *     a policy violation on every request.
 *   - The public tier allows roughly one request per second across an entire
 *     application. That budget can only be enforced somewhere shared, and a
 *     thousand browsers have no shared place to enforce it (PRD C-02, R-02).
 *   - A shared cache turns the second customer searching "Apalit" into zero
 *     requests. Per-browser caches cannot do that, and repeat searches in a
 *     small delivery radius are the common case, not the exception.
 *
 * Failures here are soft. Nominatim being slow or down must not block
 * checkout: the customer can still drop a pin on the map, which costs
 * Nominatim nothing, and a pin is the authoritative input anyway.
 */
class NominatimClient
{
    private const GATE_KEY = 'nominatim:last-call';

    private const LOCK_KEY = 'nominatim:gate';

    /**
     * Forward geocode: text in, candidate places out.
     *
     * Results are confined to the delivery region. Someone checking out a
     * chicken order in Apalit has no use for a street of the same name in
     * another province, and an out-of-area result is one the service-area
     * check would refuse a moment later anyway.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GeocoderUnavailable
     */
    public function search(string $query, int $limit = 6): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 3) {
            return [];
        }

        $key = 'geo:search:'.sha1(mb_strtolower($query).'|'.$limit);

        return $this->remember($key, (int) config('services.nominatim.search_ttl', 86400), function () use ($query, $limit) {
            $response = $this->call('/search', [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => $limit,
                'countrycodes' => 'ph',
                'viewbox' => $this->viewbox(),
                // Confine to the box rather than merely preferring it.
                'bounded' => 1,
            ]);

            return collect($response)
                ->map(fn ($place) => $this->place($place))
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * Reverse geocode: a pin in, a human-readable address out.
     *
     * Used to label a dropped pin or a detected position. A failure is not
     * fatal - the coordinates are what the order is actually placed against,
     * and the customer can type the address themselves.
     *
     * @return array<string, mixed>|null
     *
     * @throws GeocoderUnavailable
     */
    public function reverse(float $latitude, float $longitude): ?array
    {
        // ~11 m of precision. Pins a metre apart share a cache entry and the
        // same street address, which is the point.
        $key = 'geo:reverse:'.number_format($latitude, 4, '.', '').','.number_format($longitude, 4, '.', '');

        return $this->remember($key, (int) config('services.nominatim.reverse_ttl', 604800), function () use ($latitude, $longitude) {
            $response = $this->call('/reverse', [
                'lat' => $latitude,
                'lon' => $longitude,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'zoom' => 18,
            ]);

            return $this->place($response);
        });
    }

    /**
     * Cache-through, with negative results held too.
     *
     * A search that legitimately returns nothing is still an answer, and
     * caching it stops a customer who keeps hitting enter on a typo from
     * spending the shared request budget on the same miss.
     */
    private function remember(string $key, int $ttl, callable $resolve)
    {
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === '__none__' ? null : $cached;
        }

        $value = $resolve();

        Cache::put($key, $value ?? '__none__', $ttl);

        return $value;
    }

    /**
     * One HTTP call, behind the shared politeness gate.
     *
     * @return array<mixed>
     *
     * @throws GeocoderUnavailable
     */
    private function call(string $path, array $query): array
    {
        $this->waitForTurn();

        $endpoint = rtrim((string) config('services.nominatim.endpoint'), '/');

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('services.nominatim.user_agent'),
                'Accept' => 'application/json',
                // Policy asks for a contactable address alongside the agent.
                'Referer' => (string) config('app.url'),
            ])
                ->timeout((int) config('services.nominatim.timeout', 6))
                ->connectTimeout((int) config('services.nominatim.connect_timeout', 3))
                ->get($endpoint.$path, $query);
        } catch (ConnectionException $e) {
            Log::warning('Nominatim unreachable.', ['path' => $path, 'error' => $e->getMessage()]);

            throw new GeocoderUnavailable('The address service is not responding.', previous: $e);
        }

        // 429 and 403 are how Nominatim says we have overstepped. Treating
        // them as "unavailable" rather than "no results" matters: the caller
        // then tells the customer to pin the map instead of telling them their
        // address does not exist.
        if ($response->status() === 429 || $response->status() === 403) {
            Log::warning('Nominatim rate limited us.', ['status' => $response->status()]);

            throw new GeocoderUnavailable('The address service is busy. Please pin your location on the map.');
        }

        if ($response->failed()) {
            throw new GeocoderUnavailable('The address service returned an error.');
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Hold every caller to one request per interval, application-wide.
     *
     * A cache lock serialises the callers; the timestamp inside it spaces
     * them. Under load this becomes a queue, so the gate timeout is what stops
     * a checkout surge from turning into a pile of requests all waiting their
     * turn - past it, callers are told to use the map instead.
     *
     * @throws GeocoderUnavailable
     */
    private function waitForTurn(): void
    {
        $interval = (int) config('services.nominatim.min_interval_ms', 1100);
        $timeout = (int) config('services.nominatim.gate_timeout_ms', 3000);

        if ($interval <= 0) {
            return;
        }

        $lock = Cache::lock(self::LOCK_KEY, 10);

        try {
            if (! $lock->block((int) ceil($timeout / 1000))) {
                throw new GeocoderUnavailable('The address service is busy. Please pin your location on the map.');
            }
        } catch (GeocoderUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            // Lock::block throws LockTimeoutException; some cache drivers
            // cannot lock at all. Neither is a reason to fail the request
            // outright, but both mean the interval below is best-effort.
            if (str_contains(class_basename($e), 'LockTimeout')) {
                throw new GeocoderUnavailable('The address service is busy. Please pin your location on the map.');
            }

            return;
        }

        try {
            $last = (int) Cache::get(self::GATE_KEY, 0);
            $elapsed = $this->nowMs() - $last;

            if ($last > 0 && $elapsed < $interval) {
                usleep(($interval - $elapsed) * 1000);
            }

            Cache::put(self::GATE_KEY, $this->nowMs(), 60);
        } finally {
            $lock->release();
        }
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * The bounding box searches are confined to.
     *
     * Derived from the store origin and the service radius so it tracks a
     * radius change automatically, padded so a place just outside the boundary
     * is still findable - the service-area check is what refuses it, with a
     * distance the customer can see, rather than the search silently hiding it.
     */
    private function viewbox(): string
    {
        $lat = (float) config('store.origin.latitude');
        $lng = (float) config('store.origin.longitude');
        $radius = (float) config('store.service_radius_km', 45.0) * 1.25;

        $latSpan = $radius / 111.0;
        $lngSpan = $radius / max(0.1, 111.0 * cos(deg2rad($lat)));

        // Nominatim wants left,top,right,bottom.
        return implode(',', [
            round($lng - $lngSpan, 6),
            round($lat + $latSpan, 6),
            round($lng + $lngSpan, 6),
            round($lat - $latSpan, 6),
        ]);
    }

    /**
     * Reduce a Nominatim record to the fields the client actually renders.
     *
     * Nominatim returns a large, inconsistent object. Passing it through
     * whole would leak its shape into the UI and make swapping to a
     * self-hosted instance a frontend change.
     *
     * @return array<string, mixed>|null
     */
    private function place(mixed $raw): ?array
    {
        if (! is_array($raw) || ! isset($raw['lat'], $raw['lon'])) {
            return null;
        }

        $address = is_array($raw['address'] ?? null) ? $raw['address'] : [];

        $locality = $address['village']
            ?? $address['town']
            ?? $address['city']
            ?? $address['municipality']
            ?? $address['suburb']
            ?? null;

        return [
            'label' => $this->shortLabel($raw, $address),
            'full_address' => (string) ($raw['display_name'] ?? ''),
            'latitude' => (float) $raw['lat'],
            'longitude' => (float) $raw['lon'],
            'locality' => $locality,
            'province' => $address['state'] ?? $address['province'] ?? null,
            'place_id' => $raw['place_id'] ?? null,
        ];
    }

    /**
     * A one-line label: the specific part, then where it is.
     *
     * `display_name` is far too long for a result row - it runs to the
     * country and postcode - so the first two meaningful components are used
     * instead, which is how the search suggestions read in the design.
     */
    private function shortLabel(array $raw, array $address): string
    {
        $primary = $raw['name']
            ?? $address['amenity']
            ?? $address['building']
            ?? $address['road']
            ?? null;

        $secondary = $address['village']
            ?? $address['town']
            ?? $address['city']
            ?? $address['municipality']
            ?? null;

        $parts = array_values(array_filter([$primary, $secondary]));

        if ($parts !== []) {
            return implode(', ', array_unique($parts));
        }

        // Nothing usable: fall back to the first two chunks of display_name.
        $chunks = array_map('trim', explode(',', (string) ($raw['display_name'] ?? '')));

        return implode(', ', array_slice(array_filter($chunks), 0, 2)) ?: 'Selected location';
    }
}
