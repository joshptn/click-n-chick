<?php

namespace App\Utils;

use App\Services\Media\CloudinaryService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backwards-compatible facade over CloudinaryService.
 *
 * Kept because existing controllers call it statically. Two behaviours changed
 * deliberately:
 *
 *  - It no longer returns a JsonResponse on failure. Callers were assigning
 *    that return value straight into a string column, so a failed upload was
 *    persisted as a serialised response object and reported as success.
 *    It now returns null, which callers can test.
 *  - TLS verification is no longer disabled. The previous implementation
 *    passed verify=false to Guzzle, which made every upload MITM-able.
 */
class Image
{
    /** @return string|null The secure URL, or null when the upload did not happen. */
    public static function uploadImage($file, $folder): ?string
    {
        try {
            return app(CloudinaryService::class)->upload($file, $folder);
        } catch (Throwable $e) {
            Log::error('Image::uploadImage failed.', ['folder' => $folder, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public static function deleteImage($image_url, $type): void
    {
        app(CloudinaryService::class)->delete($image_url, $type);
    }
}
