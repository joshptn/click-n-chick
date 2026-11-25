<?php

namespace Tests\Feature;

use App\Services\Geo\RouteNotFound;
use App\Services\Geo\RoutingProvider;
use App\Services\Geo\RoutingUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenRouteService: the engine in use until our OSRM server is set up, and
 * the fallback after that (ROUTING_DRIVER=openrouteservice).
 */
class OpenRouteServiceClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('services.routing.driver', 'openrouteservice');
        config()->set('services.routing.openrouteservice.api_key', 'test-key');
    }

    private function routing(): RoutingProvider
    {
        return app(RoutingProvider::class);
    }

    /** The old api.openrouteservice.org host answers 410 since November 2026. */
    public function test_it_calls_the_current_host_with_the_key(): void
    {
        Http::fake(['api.heigit.org/*' => Http::response([
            'routes' => [['summary' => ['distance' => 2.4], 'geometry' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@']],
        ])]);

        $route = $this->routing()->route(14.97, 120.76);

        $this->assertSame(2.4, $route->distanceKm);
        $this->assertCount(3, $route->geometry);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.heigit.org/openrouteservice/v2/directions/driving-car'
            && $request->hasHeader('Authorization', 'test-key'));
    }

    /** The key ORS used before OSRM, so its cached answers still match. */
    public function test_its_cache_keeps_the_old_key(): void
    {
        Http::fake();
        Cache::put('route:driving-car:14.9700,120.7600', ['distance' => 4.1, 'geometry' => null], 60);

        $this->assertSame(4.1, $this->routing()->route(14.97, 120.76)->distanceKm);

        Http::assertNothingSent();
    }

    public function test_a_404_is_a_missing_route(): void
    {
        Http::fake(['api.heigit.org/*' => Http::response(['error' => ['code' => 2010]], 404)]);

        $this->expectException(RouteNotFound::class);

        $this->routing()->route(14.97, 120.76);
    }

    public function test_an_empty_answer_is_a_missing_route(): void
    {
        Http::fake(['api.heigit.org/*' => Http::response(['routes' => []])]);

        $this->expectException(RouteNotFound::class);

        $this->routing()->route(14.97, 120.76);
    }

    public function test_an_exhausted_quota_is_an_outage(): void
    {
        Http::fake(['api.heigit.org/*' => Http::response('quota exceeded', 429)]);

        try {
            $this->routing()->route(14.97, 120.76);
            $this->fail('An exhausted quota must not produce a route.');
        } catch (RoutingUnavailable $e) {
            $this->assertNotInstanceOf(RouteNotFound::class, $e);
        }
    }

    public function test_no_key_is_an_outage_and_sends_nothing(): void
    {
        config()->set('services.routing.openrouteservice.api_key', null);
        Http::fake();

        try {
            $this->routing()->route(14.97, 120.76);
            $this->fail('No key must not produce a route.');
        } catch (RoutingUnavailable) {
            Http::assertNothingSent();
        }
    }
}
