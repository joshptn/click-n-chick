<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Where an IP address roughly is, read from the local GeoLite2 City database
 * (decided 2026-11-18, for the app's device list).
 *
 * "Apalit, Philippines", or "Philippines" when the database knows only the
 * country, or null when it knows nothing, the address is private (a local or
 * test sign-in), or the database has not been downloaded yet. A lookup never
 * fails a request: anything that goes wrong reads as "no location".
 *
 * Approximate by nature. Mobile data in the Philippines shares addresses
 * across many people, so a phone often shows the network's hub (Manila,
 * Quezon City) rather than the real town; nothing here pretends otherwise.
 *
 * Nothing leaves the server: the database is a file on disk, and no address is
 * stored beyond the `last_ip_address` the device list already keeps.
 */
class IpLocator
{
    private ?Reader $reader = null;

    private bool $opened = false;

    /** @var array<string, ?string> answers already looked up in this process */
    private array $seen = [];

    public function locate(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (array_key_exists($ip, $this->seen)) {
            return $this->seen[$ip];
        }

        return $this->seen[$ip] = $this->lookup($ip);
    }

    private function lookup(string $ip): ?string
    {
        // Private, loopback and reserved ranges have no place on a map.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable $e) {
            Log::warning('GeoIP lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($record)) {
            return null;
        }

        $city = $record['city']['names']['en'] ?? null;
        $country = $record['country']['names']['en'] ?? null;

        $parts = array_values(array_filter([$city, $country], fn ($part) => is_string($part) && $part !== ''));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function reader(): ?Reader
    {
        if ($this->opened) {
            return $this->reader;
        }

        $this->opened = true;
        $path = (string) config('services.maxmind.database');

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        try {
            return $this->reader = new Reader($path);
        } catch (Throwable $e) {
            Log::warning('GeoIP database could not be opened', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
