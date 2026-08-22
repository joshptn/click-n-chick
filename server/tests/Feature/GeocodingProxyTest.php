<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Geo\NominatimClient;
use App\Services\Verification\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nominatim, proxied through Laravel (UC-DEL-001/002/003).
 *
 * The upstream is always faked here. These assert the things that keep the
 * public tier usable at all - identification, caching, geographic confinement,
 * and behaving properly when it says no (PRD C-02, R-02) - not that
 * OpenStreetMap can find an address.
 */
class GeocodingProxyTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The politeness gate sleeps to space real calls one second apart.
        // Against a fake there is nothing to be polite to, and it would add a
        // second to every test in this file.
        config()->set('services.nominatim.min_interval_ms', 0);
    }

    private function customer(): User
    {
        $phone = '+63917200'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    /** One Nominatim search hit, in Apalit. */
    private function fakeSearchResults(): array
    {
        return [
            [
                'place_id' => 123,
                'lat' => '14.9700',
                'lon' => '120.7600',
                'name' => 'ADD Street',
                'display_name' => 'ADD Street, Apalit, Pampanga, Central Luzon, 2016, Philippines',
                'address' => [
                    'road' => 'ADD Street',
                    'village' => 'Apalit',
                    'state' => 'Pampanga',
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // The proxy is not open to the world
    // -----------------------------------------------------------------

    public function test_search_requires_a_session(): void
    {
        Http::fake();

        $this->getJson('/api/geocode/search?q=apalit')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_reverse_requires_a_session(): void
    {
        Http::fake();

        $this->getJson('/api/geocode/reverse?latitude=14.97&longitude=120.76')->assertUnauthorized();

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Usage policy
    // -----------------------------------------------------------------

    /**
     * The single strongest reason this is proxied at all: a browser cannot set
     * User-Agent, and calling Nominatim without an identifying one is grounds
     * for being blocked.
     */
    public function test_every_upstream_call_identifies_the_application(): void
    {
        Http::fake(['*' => Http::response($this->fakeSearchResults())]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/search?q=apalit')
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            $agent = $request->header('User-Agent')[0] ?? '';

            return str_contains($agent, 'ClickNChick') && $agent !== '';
        });
    }

    /** Results are confined to the delivery region, not the whole planet. */
    public function test_searches_are_confined_to_the_service_region(): void
    {
        Http::fake(['*' => Http::response($this->fakeSearchResults())]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/search?q=apalit')
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['countrycodes'] ?? null) === 'ph'
                && ($query['bounded'] ?? null) === '1'
                && ! empty($query['viewbox']);
        });
    }

    /**
     * The shared budget is roughly one request per second for the whole
     * application, so the second customer searching the same thing must cost
     * nothing.
     */
    public function test_an_identical_search_is_served_from_cache(): void
    {
        Http::fake(['*' => Http::response($this->fakeSearchResults())]);

        $first = $this->customer();
        $second = $this->customer();

        $this->actingAs($first, 'sanctum')->getJson('/api/geocode/search?q=apalit')->assertOk();
        $this->actingAs($second, 'sanctum')->getJson('/api/geocode/search?q=APALIT')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_repeated_reverse_lookup_is_served_from_cache(): void
    {
        Http::fake(['*' => Http::response([
            'lat' => '14.97',
            'lon' => '120.76',
            'display_name' => 'ADD Street, Apalit, Pampanga, Philippines',
            'address' => ['road' => 'ADD Street', 'village' => 'Apalit'],
        ])]);

        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/geocode/reverse?latitude=14.97&longitude=120.76')->assertOk();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/geocode/reverse?latitude=14.97&longitude=120.76')->assertOk();

        Http::assertSentCount(1);
    }

    /** A search that found nothing is still an answer worth remembering. */
    public function test_an_empty_result_is_cached_too(): void
    {
        Http::fake(['*' => Http::response([])]);

        $user = $this->customer();

        $this->actingAs($user, 'sanctum')->getJson('/api/geocode/search?q=zzzznowhere')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/geocode/search?q=zzzznowhere')->assertOk();

        Http::assertSentCount(1);
    }

    /** Below the floor there is nothing worth asking about. */
    public function test_a_short_query_never_reaches_the_upstream(): void
    {
        Http::fake();

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/search?q=ap')
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // The shape the client actually consumes
    // -----------------------------------------------------------------

    public function test_results_are_reduced_and_carry_the_service_area_verdict(): void
    {
        Http::fake(['*' => Http::response($this->fakeSearchResults())]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/search?q=apalit')
            ->assertOk()
            ->assertJsonPath('results.0.label', 'ADD Street, Apalit')
            ->assertJsonPath('results.0.latitude', 14.97)
            ->assertJsonPath('results.0.within_service_area', true)
            ->assertJsonPath('results.0.delivery_fee', 55)
            // Nominatim's own shape must not leak through; swapping to a
            // self-hosted instance should not be a frontend change.
            ->assertJsonMissingPath('results.0.display_name');
    }

    public function test_a_result_beyond_the_radius_is_listed_but_marked_unservable(): void
    {
        Http::fake(['*' => Http::response([[
            'place_id' => 9,
            'lat' => '14.5995',
            'lon' => '120.9842',
            'name' => 'Manila City Hall',
            'display_name' => 'Manila City Hall, Manila, Philippines',
            'address' => ['city' => 'Manila'],
        ]])]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/search?q=manila+city+hall')
            ->assertOk()
            ->assertJsonPath('results.0.within_service_area', false)
            ->assertJsonPath('results.0.delivery_fee', null);
    }

    /**
     * A pin with no street address is not a failure. The coordinates are what
     * the order is placed against; the label is a convenience.
     */
    public function test_a_pin_with_no_known_address_still_returns_its_coordinates(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/reverse?latitude=14.97&longitude=120.76')
            ->assertOk()
            ->assertJsonPath('result.latitude', 14.97)
            ->assertJsonPath('result.within_service_area', true);
    }

    // -----------------------------------------------------------------
    // When the upstream says no
    // -----------------------------------------------------------------

    /** 503 with a fallback, not 500: the request was fine, the upstream was not. */
    public function test_upstream_rate_limiting_is_reported_as_unavailable_with_a_fallback(): void
    {
        Http::fake(['*' => Http::response('Bandwidth limit exceeded', 429)]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/search?q=apalit')
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'GEOCODER_UNAVAILABLE')
            ->assertJsonPath('fallback', 'map_pin');
    }

    public function test_an_upstream_outage_is_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/geocode/search?q=apalit')
            ->assertStatus(503)
            ->assertJsonPath('fallback', 'map_pin');
    }

    /** A failure must not be cached as if it were an answer. */
    public function test_a_failure_is_not_cached(): void
    {
        // One sequenced stub rather than two Http::fake() calls: a second
        // fake() merges with the first rather than replacing it, so the 429
        // would keep winning and the test would pass for the wrong reason.
        Http::fake([
            '*' => Http::sequence()
                ->push('nope', 429)
                ->push($this->fakeSearchResults(), 200),
        ]);

        $user = $this->customer();

        $this->actingAs($user, 'sanctum')->getJson('/api/geocode/search?q=apalit')->assertStatus(503);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/geocode/search?q=apalit')
            ->assertOk()
            ->assertJsonCount(1, 'results');
    }

    /** The pin path must keep working when the geocoder does not. */
    public function test_pricing_a_pin_does_not_touch_the_geocoder(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson('/api/delivery/quote', ['latitude' => 14.97, 'longitude' => 120.76])
            ->assertOk()
            ->assertJsonPath('within_service_area', true);

        Http::assertNothingSent();
    }

    public function test_the_client_reduces_a_place_without_throwing_on_missing_fields(): void
    {
        Http::fake(['*' => Http::response([['lat' => '14.97', 'lon' => '120.76']])]);

        $results = app(NominatimClient::class)->search('apalit');

        $this->assertCount(1, $results);
        $this->assertSame('Selected location', $results[0]['label']);
    }
}
