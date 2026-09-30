<?php

namespace Tests\Feature;

use App\Services\Geo\RoutingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One OpenRouteService call per coordinate, against a 2,000/day free quota.
 *
 * True concurrency is not reproducible in a single process, so the deduplication
 * is asserted as its observable property - one request per distinct coordinate -
 * alongside both ways the lock is allowed to give up. The lock exists to save
 * quota and must never be able to turn a routable address into a refusal.
 */
class DeliveryRoutingCacheTest extends TestCase
{
    /** Mirrors OpenRouteServiceClient::cacheKey(): profile, then 4-decimal lat,lng. */
    private const KEY = 'route:driving-car:14.9500,120.7600';

    protected function setUp(): void
    {
        parent::setUp();

        // The array store outlives a single test, and this cache is keyed by
        // coordinate, so one test's answer would otherwise price the next one.
        Cache::flush();

        // phpunit.xml blanks the real key. A key has to be present to get as far
        // as the faked HTTP layer.
        config()->set('services.routing.openrouteservice.api_key', 'test-key');
    }

    private function fakeRouting(float $km): void
    {
        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [['summary' => ['distance' => $km, 'duration' => $km * 90]]],
            ]),
        ]);
    }

    private function routing(): RoutingProvider
    {
        return app(RoutingProvider::class);
    }

    public function test_the_same_coordinate_is_only_fetched_once(): void
    {
        $this->fakeRouting(6.4);

        $first = $this->routing()->route(14.95, 120.76);
        $second = $this->routing()->route(14.95, 120.76);

        $this->assertSame(6.4, $first->distanceKm);
        $this->assertSame(6.4, $second->distanceKm);

        Http::assertSentCount(1);
    }

    public function test_a_different_coordinate_is_fetched_again(): void
    {
        $this->fakeRouting(6.4);

        $this->routing()->route(14.95, 120.76);
        $this->routing()->route(14.97, 120.79);

        Http::assertSentCount(2);
    }

    /** Also the guard on the key format: a changed key would reach the network. */
    public function test_a_cached_coordinate_never_reaches_the_network(): void
    {
        Http::fake();

        Cache::put(self::KEY, ['distance' => 4.1, 'geometry' => null], 60);

        $route = $this->routing()->route(14.95, 120.76);

        $this->assertSame(4.1, $route->distanceKm);

        Http::assertNothingSent();
    }

    public function test_a_locked_coordinate_still_returns_a_route_rather_than_a_refusal(): void
    {
        $this->fakeRouting(8.2);

        // No waiting: what is under test is the give-up path, not the wait.
        config()->set('services.routing.lock_wait', 0);

        $held = Cache::lock('lock:'.self::KEY, 30);

        $this->assertTrue($held->get(), 'the lock under test has to actually be held');

        $route = $this->routing()->route(14.95, 120.76);

        $this->assertSame(8.2, $route->distanceKm);

        Http::assertSentCount(1);
    }

    /** Caching inside the lock only would leak a call per contended miss. */
    public function test_a_fetch_made_without_the_lock_is_still_cached(): void
    {
        $this->fakeRouting(8.2);
        config()->set('services.routing.lock_wait', 0);

        $held = Cache::lock('lock:'.self::KEY, 30);
        $held->get();

        $this->routing()->route(14.95, 120.76);

        $held->release();

        $this->routing()->route(14.95, 120.76);

        Http::assertSentCount(1);
    }
}
