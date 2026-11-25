<?php

namespace Tests\Concerns;

/**
 * Point routing at a fake OSRM server (phpunit.xml leaves the endpoint blank,
 * so nothing can reach a real router by accident).
 *
 * Fake it with Http::fake([self::OSRM => Http::response(...)]).
 */
trait FakesOsrm
{
    protected const OSRM = 'osrm.test/*';

    /** Google's reference vector: three real, decodable points. */
    protected const OSRM_GEOMETRY = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

    protected function useFakeOsrm(): void
    {
        config()->set('services.routing.driver', 'osrm');
        config()->set('services.routing.osrm.endpoint', 'https://osrm.test');
        config()->set('services.routing.osrm.token', 'test-token');
    }

    /** OSRM's answer for one route of this many kilometres. */
    protected function osrmRoute(float $km, bool $geometry = true): array
    {
        $route = ['distance' => $km * 1000, 'duration' => $km * 90, 'weight' => $km * 90];

        if ($geometry) {
            $route['geometry'] = self::OSRM_GEOMETRY;
        }

        return [
            'code' => 'Ok',
            'routes' => [$route],
            'waypoints' => [['distance' => 0.0, 'location' => [0, 0]], ['distance' => 0.0, 'location' => [0, 0]]],
        ];
    }

    /** What OSRM says when the pin is too far from any road (HTTP 400). */
    protected function osrmNoSegment(): array
    {
        return ['code' => 'NoSegment', 'message' => 'Could not find a matching segment for any coordinate.'];
    }
}
