<?php

namespace Tests\Feature;

use App\Services\Geo\RouteNotFound;
use App\Services\Geo\RoutingProvider;
use App\Services\Geo\RoutingUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesOsrm;
use Tests\TestCase;

/**
 * OSRM first, OpenRouteService while OSRM is down (decided 2026-11-26).
 * Only an outage moves on: OSRM's "no route" is the answer.
 */
class RoutingFallbackTest extends TestCase
{
    use FakesOsrm;

    private const ORS = 'api.heigit.org/*';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->useFakeOsrm();

        config()->set('services.routing.providers', ['osrm', 'openrouteservice']);
        config()->set('services.routing.openrouteservice.api_key', 'test-key');
        config()->set('store.origin.latitude', 14.958753);
        config()->set('store.origin.longitude', 120.758469);
    }

    private function routing(): RoutingProvider
    {
        return app(RoutingProvider::class);
    }

    private function orsRoute(float $km): array
    {
        return ['routes' => [['summary' => ['distance' => $km], 'geometry' => self::OSRM_GEOMETRY]]];
    }

    public function test_osrm_answers_and_ors_is_not_asked(): void
    {
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2)), self::ORS => Http::response($this->orsRoute(2.9))]);

        $this->assertSame(3.2, $this->routing()->route(14.97, 120.76)->distanceKm);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.heigit.org'));
    }

    public function test_ors_answers_while_osrm_is_down(): void
    {
        Http::fake([self::OSRM => Http::response('down', 503), self::ORS => Http::response($this->orsRoute(2.9))]);

        $this->assertSame(2.9, $this->routing()->route(14.97, 120.76)->distanceKm);
    }

    /** The public server allows one request a second; past that it is "busy", not "no road". */
    public function test_ors_answers_while_osrm_is_rate_limiting(): void
    {
        Http::fake([self::OSRM => Http::response('Too Many Requests', 429), self::ORS => Http::response($this->orsRoute(2.9))]);

        $this->assertSame(2.9, $this->routing()->route(14.97, 120.76)->distanceKm);
    }

    public function test_no_route_from_osrm_is_final(): void
    {
        Http::fake([
            self::OSRM => Http::response(['code' => 'NoRoute', 'message' => 'Impossible route between points'], 400),
            self::ORS => Http::response($this->orsRoute(2.9)),
        ]);

        try {
            $this->routing()->route(14.97, 120.76);
            $this->fail('OSRM found no route, so there is none.');
        } catch (RouteNotFound) {
            Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.heigit.org'));
        }
    }

    public function test_both_down_is_an_outage_not_a_refusal(): void
    {
        Http::fake([self::OSRM => Http::response('down', 503), self::ORS => Http::response('down', 503)]);

        try {
            $this->routing()->route(14.97, 120.76);
            $this->fail('Two outages must not produce a route.');
        } catch (RoutingUnavailable $e) {
            $this->assertNotInstanceOf(RouteNotFound::class, $e);
        }
    }

    public function test_an_engine_left_out_of_the_list_is_never_asked(): void
    {
        config()->set('services.routing.providers', ['openrouteservice']);
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2)), self::ORS => Http::response($this->orsRoute(2.9))]);

        $this->assertSame(2.9, $this->routing()->route(14.97, 120.76)->distanceKm);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'osrm.test'));
    }

    public function test_no_engines_is_an_outage(): void
    {
        config()->set('services.routing.providers', []);
        Http::fake();

        $this->expectException(RoutingUnavailable::class);

        $this->routing()->route(14.97, 120.76);
    }
}
