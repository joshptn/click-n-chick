<?php

namespace Tests\Feature;

use App\Services\Geo\OpenRouteServiceClient;
use App\Services\Geo\OsrmClient;
use App\Services\Geo\RouteNotFound;
use App\Services\Geo\RoutingProvider;
use App\Services\Geo\RoutingUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesOsrm;
use Tests\TestCase;

/**
 * OSRM on our own server (decided 2026-11-26): how we ask, and how its
 * answers become a distance, a missing route or an outage.
 */
class OsrmClientTest extends TestCase
{
    use FakesOsrm;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->useFakeOsrm();

        config()->set('store.origin.latitude', 14.958753);
        config()->set('store.origin.longitude', 120.758469);
    }

    private function routing(): RoutingProvider
    {
        return app(RoutingProvider::class);
    }

    /** OpenRouteService until the OSRM server exists; then ROUTING_DRIVER=osrm. */
    public function test_routing_driver_picks_the_engine(): void
    {
        $this->assertInstanceOf(OsrmClient::class, $this->routing());

        config()->set('services.routing.driver', 'openrouteservice');
        $this->assertInstanceOf(OpenRouteServiceClient::class, $this->routing());

        config()->set('services.routing.driver', null);
        $this->assertInstanceOf(OpenRouteServiceClient::class, $this->routing());
    }

    public function test_it_asks_for_the_shop_to_the_pin_longitude_first_with_our_token(): void
    {
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2))]);

        $this->routing()->route(14.97, 120.76);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://osrm.test/route/v1/driving/120.758469,14.958753;120.760000,14.970000?')
                && str_contains($request->url(), 'radiuses=unlimited;350')
                && str_contains($request->url(), 'overview=full')
                && str_contains($request->url(), 'steps=false')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    /** A pin in the fishponds must not be snapped to a road far away and priced from there. */
    public function test_only_the_pin_is_held_to_the_snap_radius(): void
    {
        config()->set('services.routing.snap_metres', 120);
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2))]);

        $this->routing()->route(14.97, 120.76);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'radiuses=unlimited;120'));
    }

    public function test_without_a_token_no_authorization_header_is_sent(): void
    {
        config()->set('services.routing.osrm.token', null);
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2))]);

        $this->routing()->route(14.97, 120.76);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_metres_become_kilometres_and_the_line_is_decoded(): void
    {
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2))]);

        $route = $this->routing()->route(14.97, 120.76);

        $this->assertSame(3.2, $route->distanceKm);
        $this->assertSame([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]], $route->geometry);
    }

    public function test_a_route_without_a_line_is_still_a_route(): void
    {
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2, geometry: false))]);

        $route = $this->routing()->route(14.97, 120.76);

        $this->assertSame(3.2, $route->distanceKm);
        $this->assertNull($route->geometry);
    }

    public function test_the_answer_is_cached_under_its_own_name(): void
    {
        Http::fake([self::OSRM => Http::response($this->osrmRoute(3.2))]);

        $this->routing()->route(14.97, 120.76);

        $this->assertSame(3.2, Cache::get('route:osrm-driving:14.9700,120.7600')['distance']);
    }

    public function test_no_road_near_the_pin_is_a_missing_route(): void
    {
        Http::fake([self::OSRM => Http::response($this->osrmNoSegment(), 400)]);

        $this->expectException(RouteNotFound::class);

        $this->routing()->route(14.97, 120.76);
    }

    public function test_no_way_to_get_there_is_a_missing_route(): void
    {
        Http::fake([self::OSRM => Http::response(['code' => 'NoRoute', 'message' => 'Impossible route between points'], 400)]);

        $this->expectException(RouteNotFound::class);

        $this->routing()->route(14.97, 120.76);
    }

    public function test_an_ok_answer_with_no_route_in_it_is_a_missing_route(): void
    {
        Http::fake([self::OSRM => Http::response(['code' => 'Ok', 'routes' => []])]);

        $this->expectException(RouteNotFound::class);

        $this->routing()->route(14.97, 120.76);
    }

    /** A wrong token is our mistake, not the customer's pin: an outage, never "move the pin". */
    public function test_a_refused_token_is_an_outage(): void
    {
        Http::fake([self::OSRM => Http::response('Unauthorized', 401)]);

        try {
            $this->routing()->route(14.97, 120.76);
            $this->fail('A refused token must not produce a route.');
        } catch (RoutingUnavailable $e) {
            $this->assertNotInstanceOf(RouteNotFound::class, $e);
        }
    }

    public function test_a_bad_request_without_a_known_code_is_an_outage(): void
    {
        Http::fake([self::OSRM => Http::response(['code' => 'InvalidQuery', 'message' => 'Query string malformed'], 400)]);

        try {
            $this->routing()->route(14.97, 120.76);
            $this->fail('A malformed query must not produce a route.');
        } catch (RoutingUnavailable $e) {
            $this->assertNotInstanceOf(RouteNotFound::class, $e);
        }
    }

    public function test_an_unreachable_server_is_an_outage(): void
    {
        Http::fake([self::OSRM => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(RoutingUnavailable::class);

        $this->routing()->route(14.97, 120.76);
    }

    public function test_an_outage_is_not_cached(): void
    {
        Http::fakeSequence(self::OSRM)
            ->push('down', 503)
            ->push($this->osrmRoute(3.2));

        try {
            $this->routing()->route(14.97, 120.76);
        } catch (RoutingUnavailable) {
            // expected
        }

        $this->assertSame(3.2, $this->routing()->route(14.97, 120.76)->distanceKm);
    }
}
