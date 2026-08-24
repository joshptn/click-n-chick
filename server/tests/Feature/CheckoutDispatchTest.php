<?php

namespace Tests\Feature;

use App\Events\NotificationBroadcast;
use App\Events\OrderBroadcast;
use App\Models\Address;
use App\Models\Food;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\DeliveryQuote;
use App\Services\Store\StoreAvailability;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Dispatch step: pickup, delivery, service area, fees, and place-order.
 *
 * The governing property throughout is that the client supplies a selection
 * and a destination, never a price - so these lean on asserting what the
 * server computes rather than what the request asked for.
 */
class CheckoutDispatchTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    /** Metro Manila: 46.7 km straight-line, so past the limit before routing. */
    private const FAR = ['latitude' => 14.5995, 'longitude' => 120.9842];

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-morning, mid-week: comfortably inside trading hours, so a test
        // about delivery fees is not also a test about opening times.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', 'Asia/Manila')->utc());

        // Only the two broadcasts. A blanket Event::fake() would also silence
        // Eloquent's model events, which Setting's cache invalidation rides on
        // - several tests here change a setting and expect it to take effect.
        Event::fake([OrderBroadcast::class, NotificationBroadcast::class]);

        // The routing cache is keyed by coordinate and outlives a single test
        // in the array store, which would let one test's answer leak into the
        // next one's assertions.
        Cache::flush();

        // phpunit.xml blanks the real key so nothing can reach the live API by
        // accident. Tests that route need a key present to get as far as the
        // faked HTTP layer.
        config()->set('services.routing.openrouteservice.api_key', 'test-key');
    }

    /**
     * Answer every routing call with this many kilometres by road.
     *
     * `$geometry` mirrors what ORS actually sends - an encoded polyline
     * alongside the summary - so the geometry path is exercised by default
     * rather than only where a test names it. Pass false for the case where a
     * provider returns a distance with no drawable shape.
     */
    private function fakeRouting(float $km, bool $geometry = true): void
    {
        $route = ['summary' => ['distance' => $km, 'duration' => $km * 90]];

        if ($geometry) {
            // Google's reference vector: three real, decodable points.
            $route['geometry'] = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';
        }

        Http::fake([
            'api.openrouteservice.org/*' => Http::response(['routes' => [$route]]),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(array $overrides = []): User
    {
        $phone = '+63917000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'first_name' => 'Julia',
            'last_name' => 'Veneese',
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ], $overrides))->fresh();
    }

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Herb Roasted Chicken',
            'description' => 'Whole.',
            'price' => 350,
            'thumbnail' => 'https://example.test/chicken.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 20,
        ], $overrides));
    }

    /** @return array{0: User, 1: int} the customer and their cart line id */
    private function customerWithCart(?Food $food = null, int $quantity = 1): array
    {
        $user = $this->customer();
        $food ??= $this->food();

        $lineId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => $quantity])
            ->assertCreated()
            ->json('cart_item_id');

        return [$user, $lineId];
    }

    /** A body that clears every gate except the one under test. */
    private function pickupBody(array $overrides = []): array
    {
        return array_merge([
            'fulfilment_type' => 'pickup',
            'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(14, 0)->toIso8601String(),
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
        ], $overrides);
    }

    private function deliveryBody(array $overrides = []): array
    {
        return array_merge([
            'fulfilment_type' => 'delivery',
            'latitude' => 14.9700,
            'longitude' => 120.7600,
            'full_address' => 'ADD Street, Apalit',
            'contact_name' => 'Julia Veneese',
            'contact_phone' => '09171234567',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // UC-DEL-004 / 005: service area and fee
    //
    // BR-12 is 45 km of DRIVING, so only the routing engine can decide who is
    // servable. Haversine appears here in exactly one role - a free pre-filter
    // that can refuse, never one that can approve or price.
    // -----------------------------------------------------------------

    public function test_the_limit_is_forty_five_kilometres_of_driving(): void
    {
        $this->assertSame(45.0, app(DeliveryQuote::class)->maxDrivingKm());
    }

    public function test_a_destination_past_the_limit_in_a_straight_line_is_refused_without_routing(): void
    {
        Http::fake();

        $quote = app(DeliveryQuote::class)->for(self::FAR['latitude'], self::FAR['longitude']);

        $this->assertFalse($quote['within_service_area']);
        $this->assertNull($quote['fee']);
        $this->assertGreaterThan(45, $quote['straight_line_km']);

        // The whole point of the pre-filter: road distance can never be
        // shorter than the straight line, so this refusal is already certain
        // and must not spend a request against the daily quota.
        Http::assertNothingSent();
    }

    public function test_a_destination_within_straight_line_range_is_settled_by_the_router(): void
    {
        $this->fakeRouting(6.4);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertTrue($quote['within_service_area']);
        $this->assertSame(6.4, $quote['distance_km']);
        Http::assertSentCount(1);
    }

    /**
     * The case that only exists because the rule changed: close enough in a
     * straight line, too far once the roads are followed.
     */
    public function test_a_destination_near_in_a_straight_line_but_far_by_road_is_refused(): void
    {
        $this->fakeRouting(52.0);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertFalse($quote['within_service_area']);
        $this->assertSame(52.0, $quote['distance_km']);
        $this->assertNull($quote['fee']);
        $this->assertStringContainsString('by road', $quote['message']);
    }

    public function test_the_fee_is_computed_from_driving_distance_not_straight_line(): void
    {
        // The pin is ~1.3 km away in a straight line; the road is 12 km.
        $this->fakeRouting(12.0);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        // 3 km base + 9 chargeable km = 55 + 90.
        $this->assertSame(145.0, $quote['fee']);
        $this->assertLessThan(2, $quote['straight_line_km'], 'Straight-line would have charged the base fee alone.');
    }

    public function test_the_base_fee_covers_the_first_three_driving_kilometres(): void
    {
        $this->fakeRouting(2.8);

        $this->assertSame(55.0, app(DeliveryQuote::class)->for(14.9700, 120.7600)['fee']);
    }

    public function test_the_fee_follows_the_store_managers_configuration(): void
    {
        Setting::put(Setting::DELIVERY_BASE_FEE, 80);
        Setting::put(Setting::DELIVERY_EXTRA_FEE_PER_KM, 25);

        $this->fakeRouting(2.5);

        $this->assertSame(80.0, app(DeliveryQuote::class)->for(14.9700, 120.7600)['fee']);
    }

    // -----------------------------------------------------------------
    // When the router cannot answer
    // -----------------------------------------------------------------

    public function test_a_routing_outage_is_reported_apart_from_being_out_of_range(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response('gateway timeout', 504)]);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertFalse($quote['within_service_area']);
        $this->assertFalse($quote['routing_available']);
        $this->assertNull($quote['distance_km'], 'No distance is better than the wrong distance.');
        $this->assertNull($quote['fee']);
    }

    /**
     * ORS answering "there is no road there" is not an outage.
     *
     * It returns 404 for a destination it cannot reach - a pin in the
     * fishponds, which around Apalit takes one careless tap. Retrying is
     * useless advice; moving the pin is the fix, so the two are reported
     * apart.
     */
    public function test_an_unreachable_pin_is_reported_apart_from_an_outage(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response(
            ['error' => ['code' => 2010, 'message' => 'Could not find routable point']],
            404
        )]);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertFalse($quote['within_service_area']);
        $this->assertTrue($quote['routing_available'], 'The service answered; it just had no road.');
        $this->assertFalse($quote['route_found']);
        $this->assertStringContainsString('Move the pin', $quote['message']);
    }

    public function test_an_unreachable_pin_blocks_checkout_with_its_own_code(): void
    {
        [$user] = $this->customerWithCart();

        Http::fake(['api.openrouteservice.org/*' => Http::response([], 404)]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'NO_ROUTE_FOUND');
    }

    /** A 200 carrying no route means the same thing as the 404. */
    public function test_an_empty_route_response_is_also_a_missing_route(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => []])]);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertTrue($quote['routing_available']);
        $this->assertFalse($quote['route_found']);
    }

    public function test_an_exhausted_quota_is_treated_as_an_outage(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response('quota exceeded', 429)]);

        $this->assertFalse(app(DeliveryQuote::class)->for(14.9700, 120.7600)['routing_available']);
    }

    /**
     * A sanity check, not a correction.
     *
     * Driving distance shorter than the straight line is physically
     * impossible - swapped coordinates, a units mix-up. The route is rejected
     * rather than clamped, because clamping would quietly promote the
     * straight-line figure into the authoritative distance.
     */
    public function test_an_impossible_route_shorter_than_the_straight_line_is_rejected(): void
    {
        // Hagonoy is ~14 km away in a straight line; 3 km by road cannot be.
        $this->fakeRouting(3.0);

        $quote = app(DeliveryQuote::class)->for(14.8339, 120.7325);

        $this->assertFalse($quote['routing_available']);
        $this->assertNull($quote['distance_km']);
        $this->assertNull($quote['fee'], 'A fee must never be derived from the straight-line fallback.');
    }

    /**
     * The other side of that check, and the reason it has a tolerance.
     *
     * A router snaps both endpoints to the nearest road before measuring, so
     * over short distances the routed figure can legitimately come in under
     * the straight line. Observed live: a pin 405 m from the shop routed as
     * 360 m. Rejecting that would make deliveries to the neighbouring street
     * impossible.
     */
    public function test_a_short_route_slightly_under_the_straight_line_is_accepted(): void
    {
        // ~0.4 km away in a straight line, routed at 0.36 km after snapping.
        $this->fakeRouting(0.36);

        $quote = app(DeliveryQuote::class)->for(14.9624, 120.7584);

        $this->assertTrue($quote['routing_available']);
        $this->assertTrue($quote['within_service_area']);
        $this->assertSame(0.36, $quote['distance_km']);
        $this->assertSame(55.0, $quote['fee']);
    }

    public function test_a_missing_api_key_reads_as_an_outage_not_a_refusal(): void
    {
        config()->set('services.routing.openrouteservice.api_key', null);
        Http::fake();

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertFalse($quote['routing_available']);
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // The drawn route (decoration, never load-bearing)
    // -----------------------------------------------------------------

    public function test_a_quote_carries_the_route_line_as_coordinates(): void
    {
        $this->fakeRouting(6.4);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        // Decoded server-side, so the client never sees the provider's
        // encoding - the same reason Nominatim's shape is stripped from
        // search results.
        $this->assertSame([
            [38.5, -120.2],
            [40.7, -120.95],
            [43.252, -126.453],
        ], $quote['geometry']);
    }

    public function test_a_route_without_a_drawable_shape_is_still_a_valid_delivery(): void
    {
        $this->fakeRouting(6.4, geometry: false);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertTrue($quote['within_service_area']);
        $this->assertSame(6.4, $quote['distance_km']);
        // 3 km base, then ceil(3.4) = 4 chargeable km.
        $this->assertSame(95.0, $quote['fee']);
        $this->assertNull($quote['geometry'], 'A missing line costs a drawing, never an order.');
    }

    /** Seeing the road it would have taken is what makes a refusal legible. */
    public function test_an_out_of_range_route_still_carries_its_line(): void
    {
        $this->fakeRouting(52.0);

        $quote = app(DeliveryQuote::class)->for(14.9700, 120.7600);

        $this->assertFalse($quote['within_service_area']);
        $this->assertNotNull($quote['geometry']);
    }

    public function test_the_pre_filter_refusal_carries_no_line(): void
    {
        Http::fake();

        $quote = app(DeliveryQuote::class)->for(self::FAR['latitude'], self::FAR['longitude']);

        // Nothing was routed, so there is no road to draw.
        $this->assertNull($quote['geometry']);
        Http::assertNothingSent();
    }

    public function test_the_route_line_is_cached_with_its_distance(): void
    {
        $this->fakeRouting(6.4);

        $quote = app(DeliveryQuote::class);
        $first = $quote->for(14.9700, 120.7600);
        $second = $quote->for(14.9700, 120.7600);

        $this->assertSame($first['geometry'], $second['geometry']);
        // One request bought both the distance and the shape.
        Http::assertSentCount(1);
    }

    // -----------------------------------------------------------------
    // Quota discipline
    // -----------------------------------------------------------------

    public function test_the_same_destination_is_only_routed_once(): void
    {
        $this->fakeRouting(6.4);

        $quote = app(DeliveryQuote::class);
        $quote->for(14.9700, 120.7600);
        $quote->for(14.9700, 120.7600);
        // Eight metres away: the same doorway, and the same cache entry.
        $quote->for(14.97001, 120.76003);

        Http::assertSentCount(1);
    }

    public function test_the_router_is_asked_for_longitude_latitude_in_that_order(): void
    {
        $this->fakeRouting(6.4);

        app(DeliveryQuote::class)->for(14.9700, 120.7600);

        // ORS reverses the usual pair. Getting it wrong routes to a different
        // continent and still returns 200.
        Http::assertSent(function ($request) {
            $sent = $request->data()['coordinates'][1] ?? [];

            return $sent === [120.76, 14.97];
        });
    }

    public function test_the_delivery_quote_endpoint_prices_a_pin(): void
    {
        [$user] = $this->customerWithCart();

        $this->fakeRouting(2.5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/delivery/quote', ['latitude' => 14.9700, 'longitude' => 120.7600])
            ->assertOk()
            ->assertJsonPath('within_service_area', true)
            ->assertJsonPath('fee', 55)
            ->assertJsonPath('distance_km', 2.5)
            ->assertJsonStructure(['distance_km', 'straight_line_km', 'max_driving_km', 'origin', 'pricing']);
    }

    public function test_the_delivery_quote_endpoint_requires_a_session(): void
    {
        $this->postJson('/api/delivery/quote', ['latitude' => 14.97, 'longitude' => 120.76])
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // The checkout quote: totals
    // -----------------------------------------------------------------

    public function test_a_pickup_quote_carries_no_delivery_fee(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('subtotal', 350)
            ->assertJsonPath('delivery_fee', 0)
            ->assertJsonPath('total', 350);
    }

    public function test_a_delivery_quote_adds_the_fee_to_the_total(): void
    {
        [$user] = $this->customerWithCart();

        $this->fakeRouting(2.5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody())
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('subtotal', 350)
            ->assertJsonPath('delivery_fee', 55)
            ->assertJsonPath('total', 405);
    }

    public function test_a_routing_outage_blocks_delivery_but_leaves_pickup_alone(): void
    {
        [$user] = $this->customerWithCart();

        Http::fake(['api.openrouteservice.org/*' => Http::response('down', 503)]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'ROUTING_UNAVAILABLE');

        // Which is exactly how the shop already operates during a surge:
        // delivery off, pickup carries on.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', true);
    }

    /** BR-25: the customer checks out a subset, and is priced on that subset. */
    public function test_only_the_selected_cart_lines_are_quoted(): void
    {
        [$user, $firstLine] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food(['food_name' => 'Buttered Corn', 'price' => 60])->id])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['cart_item_ids' => [$firstLine]]))
            ->assertOk()
            ->assertJsonPath('subtotal', 350)
            ->assertJsonCount(1, 'items');
    }

    public function test_a_cart_line_belonging_to_someone_else_is_not_priced(): void
    {
        [$user] = $this->customerWithCart();
        [, $strangersLine] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['cart_item_ids' => [$strangersLine]]))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'EMPTY_SELECTION');
    }

    // -----------------------------------------------------------------
    // The checkout quote: gates
    // -----------------------------------------------------------------

    public function test_a_delivery_without_a_location_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody(['latitude' => null, 'longitude' => null]))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'LOCATION_REQUIRED');
    }

    public function test_a_delivery_beyond_the_limit_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        Http::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody(self::FAR))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'OUTSIDE_SERVICE_AREA')
            ->assertJsonPath('delivery_fee', 0);

        Http::assertNothingSent();
    }

    public function test_a_pickup_without_a_time_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['pickup_at' => null]))
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'PICKUP_TIME_REQUIRED');
    }

    public function test_a_pickup_time_inside_the_kitchen_lead_time_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody([
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->addMinutes(5)->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'PICKUP_TIME_TOO_SOON');
    }

    public function test_a_pickup_time_after_the_last_slot_is_blocked(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody([
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(19, 55)->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'PICKUP_TIME_TOO_LATE');
    }

    public function test_a_closed_store_blocks_checkout(): void
    {
        [$user] = $this->customerWithCart();

        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_CLOSED);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'STORE_CLOSED_MANUALLY');
    }

    public function test_paused_delivery_blocks_a_delivery_but_not_a_pickup(): void
    {
        [$user] = $this->customerWithCart();

        Setting::put(Setting::STORE_DELIVERY_ENABLED, false);
        $this->fakeRouting(2.5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->deliveryBody())
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'DELIVERY_UNAVAILABLE');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', true);
    }

    public function test_a_sold_out_item_blocks_checkout(): void
    {
        $food = $this->food();
        [$user] = $this->customerWithCart($food);

        $food->forceFill(['stock_quantity' => 0])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'ITEM_UNAVAILABLE');
    }

    public function test_a_malformed_mobile_number_blocks_checkout(): void
    {
        [$user] = $this->customerWithCart($this->food(), 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['contact_phone' => '12345']))
            ->assertOk()
            ->assertJsonPath('blockers.0.code', 'CONTACT_PHONE_INVALID');
    }

    public function test_a_plus_63_mobile_number_is_accepted_and_normalised(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody(['contact_phone' => '+63 917 123 4567']))
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('contact.phone', '09171234567');
    }

    public function test_the_contact_falls_back_to_the_account(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody([
                'contact_name' => null,
                'contact_phone' => null,
            ]))
            ->assertOk()
            ->assertJsonPath('contact.name', 'Julia Veneese')
            ->assertJsonPath('can_place', true);
    }

    // -----------------------------------------------------------------
    // Saved addresses (UC-PROF-009 feeding UC-ORD-004)
    // -----------------------------------------------------------------

    public function test_a_saved_address_supplies_the_destination(): void
    {
        [$user] = $this->customerWithCart();

        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'home',
            'full_address' => 'Larlin Village, Apalit',
            'latitude' => 14.9700,
            'longitude' => 120.7600,
            'location' => 'Apalit',
        ]);

        $this->fakeRouting(2.5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'fulfilment_type' => 'delivery',
                'address_id' => $address->id,
                'contact_name' => 'Julia Veneese',
                'contact_phone' => '09171234567',
            ])
            ->assertOk()
            ->assertJsonPath('can_place', true)
            ->assertJsonPath('destination.source', 'saved_address')
            ->assertJsonPath('destination.full_address', 'Larlin Village, Apalit')
            ->assertJsonPath('delivery_fee', 55);
    }

    public function test_another_accounts_address_cannot_be_used_as_a_destination(): void
    {
        [$user] = $this->customerWithCart();
        $stranger = $this->customer();

        $theirs = Address::create([
            'user_id' => $stranger->id,
            'label' => 'home',
            'full_address' => 'Somewhere else',
            'latitude' => 14.9700,
            'longitude' => 120.7600,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', [
                'fulfilment_type' => 'delivery',
                'address_id' => $theirs->id,
                'contact_name' => 'Julia Veneese',
                'contact_phone' => '09171234567',
            ])
            ->assertOk()
            ->assertJsonPath('can_place', false)
            ->assertJsonPath('blockers.0.code', 'LOCATION_REQUIRED');
    }

    // -----------------------------------------------------------------
    // Discount reporting (claimed in the next step, never here)
    // -----------------------------------------------------------------

    public function test_the_quote_reports_the_discount_without_applying_it(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout/quote', $this->pickupBody())
            ->assertOk()
            ->assertJsonPath('discount.applied', false)
            ->assertJsonPath('discount.amount', 0)
            ->assertJsonPath('total', 350);
    }

    // -----------------------------------------------------------------
    // Place order: the quote is re-run, and it is the authority
    // -----------------------------------------------------------------

    public function test_placing_a_pickup_order_records_the_dispatch_details(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickupBody())
            ->assertCreated()
            ->assertJsonPath('order.order_type', 'pickup');

        $order = Order::firstOrFail();

        $this->assertSame('350.00', $order->subtotal);
        $this->assertSame('0.00', $order->delivery_fee);
        $this->assertSame('350.00', $order->total_amount);
        $this->assertNotNull($order->pickup_at);
        $this->assertNull($order->latitude, 'A pickup order has no delivery destination.');
    }

    public function test_placing_a_delivery_order_snapshots_the_driving_distance(): void
    {
        [$user] = $this->customerWithCart();

        $this->fakeRouting(7.4);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody())
            ->assertCreated();

        $order = Order::firstOrFail();

        $this->assertSame('delivery', $order->order_type);
        // 3 km base + 5 chargeable km.
        $this->assertSame('105.00', $order->delivery_fee);
        $this->assertSame('455.00', $order->total_amount);
        $this->assertSame('ADD Street, Apalit', $order->full_address);
        // The routed figure, snapshotted - so a later map update or a change
        // of routing provider cannot rewrite what this order was charged.
        $this->assertSame('7.40', $order->delivery_distance_km);
        $this->assertNull($order->pickup_at);
    }

    /** The client sends a selection and a destination. Never a price. */
    public function test_a_price_in_the_request_body_is_ignored(): void
    {
        [$user] = $this->customerWithCart();

        $this->fakeRouting(2.5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody([
                'total_price' => 1,
                'delivery_fee' => 0,
                'subtotal' => 1,
                // Nor a distance - the only distance that counts is the one
                // the router returned.
                'delivery_distance_km' => 0.1,
            ]))
            ->assertCreated();

        $this->assertSame('405.00', Order::firstOrFail()->total_amount);
        $this->assertSame('2.50', Order::firstOrFail()->delivery_distance_km);
    }

    public function test_placing_an_order_beyond_the_driving_limit_is_refused(): void
    {
        [$user] = $this->customerWithCart();

        Http::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody(self::FAR))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'OUTSIDE_SERVICE_AREA');

        $this->assertSame(0, Order::count());
    }

    public function test_an_order_cannot_be_placed_while_routing_is_unavailable(): void
    {
        [$user] = $this->customerWithCart();

        Http::fake(['api.openrouteservice.org/*' => Http::response('down', 503)]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->deliveryBody())
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ROUTING_UNAVAILABLE');

        $this->assertSame(0, Order::count(), 'An unpriceable delivery must never be written.');
    }

    public function test_placing_an_order_while_closed_is_refused(): void
    {
        [$user] = $this->customerWithCart();

        Setting::put(Setting::STORE_ORDERING_OVERRIDE, StoreAvailability::OVERRIDE_CLOSED);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickupBody())
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'STORE_CLOSED_MANUALLY');

        $this->assertSame(0, Order::count());
    }

    /** BR-25 again: what was not checked out stays in the cart. */
    public function test_placing_an_order_clears_only_the_checked_out_lines(): void
    {
        [$user, $firstLine] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food(['food_name' => 'Buttered Corn', 'price' => 60])->id])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', $this->pickupBody(['cart_item_ids' => [$firstLine]]))
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(1, 'cart')
            ->assertJsonPath('cart.0.food_name', 'Buttered Corn');
    }

    public function test_placing_an_order_without_a_fulfilment_type_is_refused(): void
    {
        [$user] = $this->customerWithCart();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order/place', ['contact_phone' => '09171234567'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'FULFILMENT_TYPE_REQUIRED');
    }
}
