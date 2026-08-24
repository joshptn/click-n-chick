<?php

namespace App\Http\Controllers;

use App\Services\Geo\GeocoderUnavailable;
use App\Services\Geo\NominatimClient;
use App\Services\Orders\DeliveryQuote;
use Illuminate\Http\Request;

class GeocodingController extends Controller
{
    public function __construct(
        private NominatimClient $geocoder,
        private DeliveryQuote $delivery,
    ) {}

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

    private function withServiceArea(array $place): array
    {
        $latitude = (float) $place['latitude'];
        $longitude = (float) $place['longitude'];

        return $place + [
            'straight_line_km' => $this->delivery->straightLineKm($latitude, $longitude),
            'possibly_in_range' => $this->delivery->possiblyInRange($latitude, $longitude),
            'max_driving_km' => $this->delivery->maxDrivingKm(),
        ];
    }

    private function unavailable(GeocoderUnavailable $e)
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error_code' => 'GEOCODER_UNAVAILABLE',
            'fallback' => 'map_pin',
        ], 503);
    }
}
