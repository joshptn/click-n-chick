<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Keeps the GeoLite2 City database on disk (decided 2026-11-18).
 *
 * Scheduled often but cheap: it downloads only when the file is missing (a
 * fresh container on Render starts without it) or older than max_age_days
 * (GeoLite's licence: a copy is deleted within 30 days of a newer release).
 * A failed download is not retried for an hour, so a wrong licence key cannot
 * burn through MaxMind's daily download allowance.
 *
 * Without MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY it does nothing, and the
 * device list simply shows no location.
 */
class UpdateGeoIpDatabase extends Command
{
    protected $signature = 'geoip:update {--force : Download even when the current copy is fresh}';

    protected $description = 'Download the GeoLite2 City database when it is missing or out of date';

    /** Starts the metadata section of every MaxMind database file. */
    private const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

    private const BACKOFF_KEY = 'geoip:update:failed';

    public function handle(): int
    {
        $accountId = (string) config('services.maxmind.account_id');
        $licenseKey = (string) config('services.maxmind.license_key');
        $target = (string) config('services.maxmind.database');

        if ($accountId === '' || $licenseKey === '') {
            $this->info('GeoIP is not configured (MAXMIND_ACCOUNT_ID / MAXMIND_LICENSE_KEY); skipped.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->isFresh($target)) {
            $this->info('The GeoIP database is up to date.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && Cache::has(self::BACKOFF_KEY)) {
            $this->info('The last download failed less than an hour ago; waiting before trying again.');

            return self::SUCCESS;
        }

        try {
            $this->download($accountId, $licenseKey, $target);
        } catch (Throwable $e) {
            Cache::put(self::BACKOFF_KEY, true, now()->addHour());
            // The message never carries the key: it is sent as Basic auth.
            $this->error('GeoIP download failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Cache::forget(self::BACKOFF_KEY);
        $this->info('GeoIP database updated.');

        return self::SUCCESS;
    }

    private function isFresh(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $maxAge = max(1, (int) config('services.maxmind.max_age_days', 7)) * 86400;

        return time() - (int) filemtime($path) < $maxAge;
    }

    private function download(string $accountId, string $licenseKey, string $target): void
    {
        $edition = (string) config('services.maxmind.edition', 'GeoLite2-City');
        $url = rtrim((string) config('services.maxmind.endpoint'), '/')."/{$edition}/download";

        // MaxMind answers with a redirect to its storage; Guzzle follows it and
        // drops the Authorization header on the way, as it should.
        $response = Http::withBasicAuth($accountId, $licenseKey)
            ->timeout((int) config('services.maxmind.timeout', 120))
            ->get($url, ['suffix' => 'tar.gz']);

        if (! $response->successful()) {
            throw new \RuntimeException("MaxMind answered HTTP {$response->status()}.");
        }

        $directory = dirname($target);
        File::ensureDirectoryExists($directory);

        $archive = $directory.'/download-'.getmypid().'.tar.gz';
        $staged = $target.'.new';

        try {
            File::put($archive, $response->body());
            $this->extract($archive, $staged);

            // Replace in one step, so a lookup never reads a half-written file.
            if (! @rename($staged, $target)) {
                throw new \RuntimeException('The new database could not be moved into place.');
            }
        } finally {
            File::delete([$archive, $staged]);
        }
    }

    /** Copy the .mmdb out of MaxMind's tar.gz (one folder holding it and the licence files). */
    private function extract(string $archive, string $destination): void
    {
        $found = null;

        foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
            if (str_ends_with($file->getFilename(), '.mmdb')) {
                $found = $file->getPathname();
                break;
            }
        }

        if ($found === null) {
            throw new \RuntimeException('The download held no database file.');
        }

        if (! copy($found, $destination)) {
            throw new \RuntimeException('The database could not be unpacked.');
        }

        // A truncated or wrong file would make every lookup fail silently.
        $tail = (string) file_get_contents($destination, false, null, max(0, filesize($destination) - 131072));

        if (! str_contains($tail, self::METADATA_MARKER)) {
            throw new \RuntimeException('The downloaded file is not a MaxMind database.');
        }
    }
}
