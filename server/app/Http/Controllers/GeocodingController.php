<?php

namespace App\Http\Controllers;

use App\Services\Geo\GeocoderUnavailable;
use App\Services\Geo\NominatimClient;
use App\Services\Orders\DeliveryQuote;
use Illuminate\Http\Request;

/**
 * Address search and reverse lookup, proxied (UC-DEL-001/002/003).
 *
 * Authenticated on purpose. An open geocoding proxy is a free Nominatim
 * gateway for anyone who finds the URL, and the shared request budget it would
 * burn belongs to real customers at checkout. Guest checkout is a separate
 * flow with its own token, and can be admitted here when it lands.
 *
 * Every response carries the service-area verdict alongside the coordinates,
 * so the UI can grey out an undeliverable result at the moment it is listed
 * rather than after it is chosen.
 */
class GeocodingController extends Controller
{
    public function __construct(
        private NominatimClient $geocoder,
        private DeliveryQuote $delivery,
    ) {}

    /** GET /api/geocode/search?q= */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:160'],
        ]);

        try {
            $results = $this->geocoder->search($validated['q']);
        } catch (GeocoderUnavailable $e) {
            return $this->unavailable($e);
        }

        return response()->json([
            'query' => $validated['q'],
            'results' => array_map(fn (array $place) => $this->withServiceArea($place), $results),
        ]);
    }

    /** GET /api/geocode/reverse?latitude=&longitude= */
    public function reverse(Request $request)
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];

        try {
            $place = $this->geocoder->reverse($latitude, $longitude);
        } catch (GeocoderUnavailable $e) {
            return $this->unavailable($e);
        }

        // No street address for this pin is not an error. The coordinates are
        // what the order is placed against; the label is a convenience, and
        // the customer can write their own.
        $place ??= [
            'label' => 'Pinned location',
            'full_address' => '',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'locality' => null,
            'province' => null,
            'place_id' => null,
        ];

        return response()->json([
            'result' => $this->withServiceArea($place),
        ]);
    }

    /** @return array<string, mixed> */
    private function withServiceArea(array $place): array
    {
        $quote = $this->delivery->for((float) $place['latitude'], (float) $place['longitude']);

        return $place + [
            'distance_km' => $quote['distance_km'],
            'within_service_area' => $quote['within_service_area'],
            'delivery_fee' => $quote['fee'],
        ];
    }

    /**
     * 503, not 500: the request was fine, the upstream was not.
     *
     * `fallback` tells the client this is recoverable by pinning the map,
     * which is the one path that does not depend on Nominatim at all.
     */
    private function unavailable(GeocoderUnavailable $e)
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error_code' => 'GEOCODER_UNAVAILABLE',
            'fallback' => 'map_pin',
        ], 503);
    }
}
