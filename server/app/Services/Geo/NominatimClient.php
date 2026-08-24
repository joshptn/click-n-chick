<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NominatimClient
{
    private const GATE_KEY = 'nominatim:last-call';

    private const LOCK_KEY = 'nominatim:gate';

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
                'bounded' => 1,
            ]);

            return collect($response)
                ->map(fn ($place) => $this->place($place))
                ->filter()
                ->values()
                ->all();
        });
    }

    public function reverse(float $latitude, float $longitude): ?array
    {
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

    private function call(string $path, array $query): array
    {
        $this->waitForTurn();

        $endpoint = rtrim((string) config('services.nominatim.endpoint'), '/');

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('services.nominatim.user_agent'),
                'Accept' => 'application/json',
                'Referer' => (string) config('app.url'),
            ])
                ->timeout((int) config('services.nominatim.timeout', 6))
                ->connectTimeout((int) config('services.nominatim.connect_timeout', 3))
                ->get($endpoint.$path, $query);
        } catch (ConnectionException $e) {
            Log::warning('Nominatim unreachable.', ['path' => $path, 'error' => $e->getMessage()]);

            throw new GeocoderUnavailable('The address service is not responding.', previous: $e);
        }

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
    private function viewbox(): string
    {
        $lat = (float) config('store.origin.latitude');
        $lng = (float) config('store.origin.longitude');
        $radius = (float) config('store.max_driving_km', 45.0) * 1.25;

        $latSpan = $radius / 111.0;
        $lngSpan = $radius / max(0.1, 111.0 * cos(deg2rad($lat)));

        return implode(',', [
            round($lng - $lngSpan, 6),
            round($lat + $latSpan, 6),
            round($lng + $lngSpan, 6),
            round($lat - $latSpan, 6),
        ]);
    }

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
        $chunks = array_map('trim', explode(',', (string) ($raw['display_name'] ?? '')));

        return implode(', ', array_slice(array_filter($chunks), 0, 2)) ?: 'Selected location';
    }
}
