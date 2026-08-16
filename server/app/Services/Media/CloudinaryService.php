<?php

namespace App\Services\Media;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The single place that talks to Cloudinary.
 *
 * Credentials are resolved from EITHER form, because the two that already
 * existed in this project disagreed: config/cloudinary.php reads a single
 * CLOUDINARY_URL, while config/filesystems.php reads three separate
 * CLOUDINARY_CLOUD_NAME / _KEY / _SECRET vars. App\Utils\Image read only the
 * second set, so setting CLOUDINARY_URL alone - the form Cloudinary's own
 * dashboard hands you - left every upload silently unauthenticated.
 *
 * Resolving both here means pasting either form into .env is enough, with no
 * follow-up configuration.
 */
class CloudinaryService
{
    /** @var array{cloud_name: ?string, api_key: ?string, api_secret: ?string}|null */
    private ?array $credentials = null;

    /**
     * @return array{cloud_name: ?string, api_key: ?string, api_secret: ?string}
     */
    public function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        // CLOUDINARY_URL wins when it carries a complete triple, since that is
        // the value Cloudinary gives you verbatim.
        $fromUrl = $this->parseUrl((string) config('cloudinary.cloud_url'));

        if ($fromUrl !== null) {
            return $this->credentials = $fromUrl;
        }

        return $this->credentials = [
            'cloud_name' => config('filesystems.disks.cloudinary.cloud') ?: null,
            'api_key' => config('filesystems.disks.cloudinary.key') ?: null,
            'api_secret' => config('filesystems.disks.cloudinary.secret') ?: null,
        ];
    }

    public function isConfigured(): bool
    {
        $c = $this->credentials();

        return filled($c['cloud_name']) && filled($c['api_key']) && filled($c['api_secret']);
    }

    /** Why it is unusable, for diagnostics and command output. */
    public function unavailableReason(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        return 'Cloudinary is not configured. Set CLOUDINARY_URL (or CLOUDINARY_CLOUD_NAME, '
            .'CLOUDINARY_KEY and CLOUDINARY_SECRET) in .env, then run: php artisan config:clear';
    }

    /**
     * Upload a local file or a remote URL and return the secure URL.
     *
     * Cloudinary fetches remote sources itself, which is what lets the seeders
     * publish their images without shipping binaries into the repository.
     *
     * @throws RuntimeException when unconfigured or the upload is refused.
     */
    public function upload(UploadedFile|string $source, string $folder): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException($this->unavailableReason());
        }

        if ($source instanceof UploadedFile) {
            if (! $source->isValid()) {
                throw new RuntimeException('The uploaded file is not valid.');
            }

            $path = $source->getRealPath();
        } else {
            $path = $source;
        }

        try {
            $result = $this->client()->uploadApi()->upload($path, [
                'folder' => $folder,
                // Re-uploading the same source to the same folder replaces the
                // asset rather than accumulating duplicates on every reseed.
                'overwrite' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('Cloudinary upload failed.', ['folder' => $folder, 'error' => $e->getMessage()]);

            throw new RuntimeException('Cloudinary refused the upload: '.$e->getMessage(), 0, $e);
        }

        $url = $result['secure_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Cloudinary returned no secure_url.');
        }

        return $url;
    }

    /** Best-effort delete. Never throws - a stale asset must not fail a request. */
    public function delete(?string $url, string $folder): void
    {
        if (! $this->isConfigured() || ! $this->isCloudinaryUrl($url)) {
            return;
        }

        try {
            $publicId = $this->publicIdFor($url, $folder);

            if ($publicId !== null) {
                $this->client()->uploadApi()->destroy($publicId);
            }
        } catch (Throwable $e) {
            Log::warning('Cloudinary delete failed.', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }

    public function isCloudinaryUrl(?string $url): bool
    {
        return is_string($url) && str_contains($url, 'res.cloudinary.com');
    }

    private function client(): Cloudinary
    {
        $c = $this->credentials();

        return new Cloudinary([
            'cloud' => [
                'cloud_name' => $c['cloud_name'],
                'api_key' => $c['api_key'],
                'api_secret' => $c['api_secret'],
            ],
            'url' => ['secure' => true],
        ]);
    }

    /**
     * 'cloudinary://key:secret@cloud' -> the triple, or null when incomplete.
     *
     * config/cloudinary.php builds this string from the discrete vars when
     * CLOUDINARY_URL is unset, producing 'cloudinary://:@' - hence the
     * completeness check rather than a bare parse.
     */
    private function parseUrl(string $url): ?array
    {
        if ($url === '' || ! str_starts_with($url, 'cloudinary://')) {
            return null;
        }

        $parts = parse_url($url);

        $cloud = $parts['host'] ?? null;
        $key = $parts['user'] ?? null;
        $secret = $parts['pass'] ?? null;

        if (blank($cloud) || blank($key) || blank($secret)) {
            return null;
        }

        return ['cloud_name' => $cloud, 'api_key' => $key, 'api_secret' => $secret];
    }

    /** Recover 'folder/public_id' from a delivery URL. */
    private function publicIdFor(string $url, string $folder): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        $file = end($segments);

        if ($file === false || $file === '') {
            return null;
        }

        return $folder.'/'.pathinfo($file, PATHINFO_FILENAME);
    }
}
