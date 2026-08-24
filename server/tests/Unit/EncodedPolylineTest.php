<?php

namespace Tests\Unit;

use App\Services\Geo\EncodedPolyline;
use PHPUnit\Framework\TestCase;

/**
 * The encoded polyline decoder.
 *
 * A route arrives as a compressed delta stream, and a decoder that is subtly
 * wrong does not fail loudly - it draws a line through the wrong hemisphere,
 * or drifts a little further off with every point. So these check the known
 * reference vector, the sign handling that is easiest to get backwards, and
 * that malformed input degrades instead of throwing.
 */
class EncodedPolylineTest extends TestCase
{
    /**
     * The example from Google's own specification, which every implementation
     * of this format is expected to reproduce exactly.
     */
    public function test_it_decodes_the_reference_vector(): void
    {
        $points = EncodedPolyline::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

        $this->assertSame([
            [38.5, -120.2],
            [40.7, -120.95],
            [43.252, -126.453],
        ], $points);
    }

    /** Deltas accumulate, so one dropped sign sends the rest of the line adrift. */
    public function test_it_accumulates_negative_deltas(): void
    {
        $points = EncodedPolyline::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

        $this->assertGreaterThan($points[0][0], $points[1][0], 'Latitude should climb.');
        $this->assertLessThan($points[0][1], $points[1][1], 'Longitude should fall.');
    }

    public function test_an_empty_string_decodes_to_nothing(): void
    {
        $this->assertSame([], EncodedPolyline::decode(''));
    }

    /**
     * A response cut short mid-point must not throw or emit a half-decoded
     * coordinate - the caller treats a short line as "no geometry" and draws
     * nothing, which is the correct outcome.
     */
    public function test_a_truncated_string_returns_only_whole_points(): void
    {
        $points = EncodedPolyline::decode('_p~iF~ps|U_ulL');

        $this->assertCount(1, $points);
        $this->assertSame([38.5, -120.2], $points[0]);
    }

    /**
     * Ordering, which the reference vector settles on its own.
     *
     * Its first point is [38.5, -120.2]: a latitude that is a valid longitude
     * and a longitude that is not a valid latitude. A decoder that emitted the
     * pair backwards could not produce this, and Leaflet would silently draw
     * the route in the wrong hemisphere rather than complain.
     */
    public function test_it_returns_latitude_before_longitude(): void
    {
        [$latitude, $longitude] = EncodedPolyline::decode('_p~iF~ps|U')[0];

        $this->assertSame(38.5, $latitude);
        $this->assertSame(-120.2, $longitude);
        $this->assertLessThanOrEqual(90, abs($latitude), 'A latitude past 90 means the pair is reversed.');
    }
}
