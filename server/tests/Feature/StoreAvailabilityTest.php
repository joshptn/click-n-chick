<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operating hours, the manual override, and delivery availability.
 *
 * Every case pins the clock to a real Asia/Manila moment. The application
 * clock is UTC, so a test that trusted "now" would pass or fail depending on
 * what time of day it ran - the exact bug this service exists to prevent.
 */
class StoreAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function store(): StoreAvailability
    {
        return app(StoreAvailability::class);
    }

    /** Freeze the clock at a wall-clock time in the store's timezone. */
    private function atManilaTime(string $time): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-24 '.$time, 'Asia/Manila')->utc()
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // BR-15: 7:00 AM - 8:00 PM by default
    // -----------------------------------------------------------------

    public function test_the_store_accepts_orders_inside_the_default_window(): void
    {
        $this->atManilaTime('12:00');

        $this->assertTrue($this->store()->acceptsOrders());
    }

    public function test_the_store_refuses_orders_before_opening(): void
    {
        $this->atManilaTime('06:59');

        $this->assertFalse($this->store()->acceptsOrders());
    }

    public function test_the_store_refuses_orders_after_closing(): void
    {
        $this->atManilaTime('20:00');

        $this->assertFalse($this->store()->acceptsOrders());
    }

    public function test_opening_is_inclusive_and_closing_is_exclusive(): void
    {
        $this->atManilaTime('07:00');
        $this->assertTrue($this->store()->acceptsOrders(), 'The store opens at 7:00 sharp.');

        $this->atManilaTime('19:59');
        $this->assertTrue($this->store()->acceptsOrders(), 'The last minute before close still trades.');
    }

    /**
     * The application clock is UTC. 23:00 UTC is 07:00 the next day in Manila,
     * so a service reading the default clock would call this closed.
     */
    public function test_hours_are_read_in_the_store_timezone_not_utc(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 23:30', 'UTC'));

        $this->assertSame('Asia/Manila', $this->store()->timezone());
        $this->assertTrue(
            $this->store()->acceptsOrders(),
            '23:30 UTC is 07:30 in Manila, which is inside trading hours.'
        );
    }

    // -----------------------------------------------------------------
    // UC-OPS-011: configurable hours
    // -----------------------------------------------------------------

    public function test_configured_hours_replace_the_defaults(): void
    {
        Setting::put(Setting::STORE_OPENS_AT, '09:00');
        Setting::put(Setting::STORE_CLOSES_AT, '17:00');

        $this->atManilaTime('08:30');
        $this->assertFalse($this->store()->acceptsOrders());

        $this->atManilaTime('09:30');
        $this->assertTrue($this->store()->acceptsOrders());
    }

    public function test_a_malformed_stored_time_falls_back_rather_than_throwing(): void
    {
        Setting::put(Setting::STORE_OPENS_AT, 'not-a-time');

        $this->atManilaTime('12:00');

        $this->assertSame('07:00', $this->store()->opensAt());
        $this->assertTrue($this->store()->acceptsOrders());
    }

    // -----------------------------------------------------------------
    // UC-OPS-009: the override beats the clock, in both directions
    // -----------------------------------------------------------------

    public function test_a_manual_closure_overrides_open_hours(): void
    {
        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_CLOSED);

        $this->atManilaTime('12:00');

        $this->assertFalse($this->store()->acceptsOrders());
        $this->assertSame('STORE_CLOSED_MANUALLY', $this->store()->blocker('pickup')['code']);
    }

    public function test_a_manual_opening_overrides_closed_hours(): void
    {
        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_OPEN);

        $this->atManilaTime('23:00');

        $this->assertTrue(
            $this->store()->acceptsOrders(),
            'Overriding scheduled hours has to mean opening late as well as closing early.'
        );
    }

    public function test_a_manual_closure_advertises_no_reopening_time(): void
    {
        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_CLOSED);

        $this->atManilaTime('12:00');

        $this->assertNull(
            $this->store()->nextOpensAt(),
            'An unplanned closure has no scheduled end, and guessing one would promise something the store did not.'
        );
    }

    public function test_a_scheduled_closure_reports_when_it_reopens(): void
    {
        $this->atManilaTime('22:00');

        $next = $this->store()->nextOpensAt();

        $this->assertNotNull($next);
        $this->assertSame('07:00', $next->format('H:i'));
        $this->assertSame('2026-08-25', $next->format('Y-m-d'), 'Past closing, the next opening is tomorrow.');
    }

    // -----------------------------------------------------------------
    // UC-OPS-010: delivery pauses on its own
    // -----------------------------------------------------------------

    public function test_delivery_can_be_paused_while_pickup_continues(): void
    {
        Setting::put(Setting::STORE_DELIVERY_ENABLED, false);

        $this->atManilaTime('12:00');

        $this->assertTrue($this->store()->acceptsOrders());
        $this->assertFalse($this->store()->acceptsDelivery());
        $this->assertNull($this->store()->blocker('pickup'));
        $this->assertSame('DELIVERY_UNAVAILABLE', $this->store()->blocker('delivery')['code']);
    }

    public function test_a_closed_store_blocks_delivery_with_the_closure_not_the_delivery_reason(): void
    {
        Setting::put(Setting::STORE_DELIVERY_ENABLED, false);

        $this->atManilaTime('03:00');

        $this->assertSame(
            'STORE_CLOSED',
            $this->store()->blocker('delivery')['code'],
            'The customer needs the reason they can act on first.'
        );
    }

    /** A false flag must survive the round-trip through a string column. */
    public function test_a_stored_false_flag_reads_back_as_false(): void
    {
        Setting::put(Setting::STORE_DELIVERY_ENABLED, false);

        $this->assertFalse(Setting::boolean(Setting::STORE_DELIVERY_ENABLED, true));

        Setting::put(Setting::STORE_DELIVERY_ENABLED, true);

        $this->assertTrue(Setting::boolean(Setting::STORE_DELIVERY_ENABLED, false));
    }

    // -----------------------------------------------------------------
    // The public endpoint
    // -----------------------------------------------------------------

    public function test_the_status_endpoint_is_public(): void
    {
        $this->atManilaTime('12:00');

        $this->getJson('/api/store/status')
            ->assertOk()
            ->assertJsonPath('accepting_orders', true)
            ->assertJsonPath('timezone', 'Asia/Manila')
            ->assertJsonPath('opens_at', '07:00')
            ->assertJsonPath('closes_at', '20:00')
            ->assertJsonStructure(['pickup_window' => ['earliest', 'latest', 'available'], 'blockers']);
    }

    // -----------------------------------------------------------------
    // Same-day pickup window
    // -----------------------------------------------------------------

    public function test_the_pickup_window_starts_after_the_kitchen_lead_time(): void
    {
        $this->atManilaTime('12:00');

        $window = $this->store()->pickupWindow();

        $this->assertSame('12:20', $window['earliest']->format('H:i'));
        $this->assertTrue($window['available']);
    }

    public function test_the_pickup_window_ends_before_closing(): void
    {
        $this->atManilaTime('12:00');

        $this->assertSame(
            '19:45',
            $this->store()->pickupWindow()['latest']->format('H:i'),
            'The last slot must leave someone there to hand the food over.'
        );
    }

    public function test_the_pickup_window_closes_once_the_lead_time_no_longer_fits(): void
    {
        $this->atManilaTime('19:50');

        $this->assertFalse(
            $this->store()->pickupWindow()['available'],
            'Nothing ordered now could be collected before the last slot.'
        );
    }
}
