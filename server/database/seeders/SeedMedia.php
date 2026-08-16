<?php

namespace Database\Seeders;

use App\Services\Media\CloudinaryService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes seed imagery to Cloudinary when it is configured.
 *
 * Seed rows carry a remote source URL rather than a binary shipped in the
 * repository. Cloudinary can fetch a remote URL itself, so when credentials
 * exist this uploads each one and the row stores the resulting Cloudinary
 * URL; when they do not, the row keeps the source URL and the menu still
 * renders.
 *
 * That is what makes the credentials the only outstanding step: adding them
 * and reseeding - or running `php artisan media:sync-cloudinary` - moves every
 * image across with no code or config change.
 */
class SeedMedia
{
    private static ?bool $configured = null;

    private static bool $warned = false;

    /** @var array<string, string> source URL => resolved URL, per run */
    private static array $cache = [];

    public static function publish(string $sourceUrl, string $folder): string
    {
        if (! self::isConfigured()) {
            self::warnOnce();

            return $sourceUrl;
        }

        if (isset(self::$cache[$sourceUrl])) {
            return self::$cache[$sourceUrl];
        }

        try {
            return self::$cache[$sourceUrl] = app(CloudinaryService::class)->upload($sourceUrl, $folder);
        } catch (Throwable $e) {
            // A seed run must not die on a flaky upload. The source URL still
            // renders, and the sync command can retry later.
            Log::warning('Seed media upload failed; keeping the source URL.', [
                'source' => $sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return $sourceUrl;
        }
    }

    public static function isConfigured(): bool
    {
        return self::$configured ??= app(CloudinaryService::class)->isConfigured();
    }

    private static function warnOnce(): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;

        Log::info('Cloudinary is not configured; seed images kept their source URLs. '
            .'Add CLOUDINARY_URL to .env and run `php artisan media:sync-cloudinary` to move them across.');
    }
}
