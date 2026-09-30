<?php

namespace App\Services\Geo;

/**
 * Google's encoded polyline format, which most routing engines return.
 *
 * A route is a long list of nearly-identical coordinates, so the format stores
 * each point as a delta from the previous one, in base64-ish chunks. A few
 * hundred points compress to a short string.
 *
 * Decoded here rather than in the browser so the API hands out plain
 * coordinates. The encoding is a detail of whichever engine is answering, and
 * swapping OpenRouteService for OSRM should not be a frontend change - the
 * same reasoning that keeps Nominatim's response shape out of search results.
 */
class EncodedPolyline
{
    /**
     * @return array<int, array{0: float, 1: float}> [lat, lng] pairs
     */
    public static function decode(string $encoded, int $precision = 5): array
    {
        if ($encoded === '') {
            return [];
        }

        $factor = 10 ** $precision;
        $length = strlen($encoded);

        $index = 0;
        $lat = 0;
        $lng = 0;
        $points = [];

        while ($index < $length) {
            foreach (['lat', 'lng'] as $axis) {
                $shift = 0;
                $result = 0;

                do {
                    if ($index >= $length) {
                        return $points;
                    }

                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);

                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);

                if ($axis === 'lat') {
                    $lat += $delta;
                } else {
                    $lng += $delta;
                }
            }

            $points[] = [round($lat / $factor, 6), round($lng / $factor, 6)];
        }

        return $points;
    }
}
