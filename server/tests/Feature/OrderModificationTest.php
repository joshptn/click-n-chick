<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Correcting a placed order instead of cancelling it.
 *
 * Most people who reach for "cancel" have typed something wrong, and the whole
 * point of this surface is that a typo should not cost the shop an order. What
 * it must never do is let a correction quietly change what the customer owes -
 * so the delivery fee is the gate, and anything that moves it is refused.
 */
class OrderModificationTest extends TestCase
{
    use RefreshDatabase;

    /** Roughly 2.5 km away: comfortably inside the base delivery band. */
    private const NEAR = ['latitude' => 14.9700, 'longitude' => 120.7600];

    /** What the routing engine will say next, in km. */
    private float $drivingKm = 2.5;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00', 'Asia/Manila')->utc());
        Cache::flush();

        config()->set('services.routing.openrouteservice.api_key', 'test-key');

        // A closure, not a stub map. Http::fake() MERGES stubs rather than
        // replacing them, so a second fake() would never override the first -
        // every re-route would silently keep answering 2.5 km.
        Http::fake(fn () => Http::response([
            'routes' => [['summary' => ['distance' => $this->drivingKm, 'duration' => 600]]],
        ]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Pin what the routing engine reports back, in km. */
    private function route(float $km): void
    {
        $this->drivingKm = $km;

        // Routes are cached per destination, so a changed answer for the same
        // pin would otherwise never be asked for.
        Cache::flush();
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER]);
    }

    private function order(
        User $owner,
        string $type = 'delivery',
        string $status = OrderStatus::PLACED,
        float $fee = 55.0,
    ): Order {
        $order = Order::create([
            'user_id' => $owner->id,
            'order_type' => $type,
            'status' => OrderStatus::PLACED,
            'subtotal' => 410,
            'delivery_fee' => $type === 'delivery' ? $fee : 0,
            'delivery_distance_km' => $type === 'delivery' ? 2.5 : null,
            'total_amount' => 410 + ($type === 'delivery' ? $fee : 0),
            'total_price' => 410 + ($type === 'delivery' ? $fee : 0),
            'full_address' => $type === 'delivery' ? 'Old Street, Apalit' : null,
            'latitude' => $type === 'delivery' ? self::NEAR['latitude'] : null,
            'longitude' => $type === 'delivery' ? self::NEAR['longitude'] : null,
            'pickup_at' => $type === 'pickup'
                ? CarbonImmutable::now('Asia/Manila')->setTime(14, 0)
                : null,
        ]);

        if ($status !== OrderStatus::PLACED) {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->fresh();
    }

    // -----------------------------------------------------------------
    // The address
    // -----------------------------------------------------------------

    public function test_a_customer_can_correct_the_delivery_address(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'latitude' => 14.9710,
                'longitude' => 120.7610,
                'full_address' => '14 New Street, Apalit',
            ])
            ->assertOk()
            ->assertJsonPath('order.delivery.full_address', '14 New Street, Apalit');

        $this->assertSame('14 New Street, Apalit', $order->fresh()->full_address);
    }

    public function test_a_saved_address_can_be_chosen_instead(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);

        $address = Address::create([
            'user_id' => $customer->id,
            'label' => 'Home',
            'full_address' => '7 Rizal Street, Apalit',
            'latitude' => 14.9705,
            'longitude' => 120.7605,
            'location' => 'Apalit',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('order.delivery.full_address', '7 Rizal Street, Apalit');

        $this->assertSame($address->id, $order->fresh()->address_id);
    }

    public function test_an_address_that_would_change_the_fee_is_refused(): void
    {
        $order = $this->order($customer = $this->customer());

        // Same order, much longer drive: a different fee band.
        $this->route(18.0);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'latitude' => 15.0500,
                'longitude' => 120.8500,
                'full_address' => 'Far Away, Pampanga',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'FEE_WOULD_CHANGE');

        $this->assertSame('Old Street, Apalit', $order->fresh()->full_address, 'nothing may be written on a refusal');
    }

    public function test_an_address_outside_the_service_area_is_refused(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->route(80.0);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'latitude' => 15.2000,
                'longitude' => 121.0000,
                'full_address' => 'Well outside, Nueva Ecija',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OUTSIDE_SERVICE_AREA');
    }

    public function test_the_recorded_distance_follows_the_new_address(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->route(2.9);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'latitude' => 14.9720,
                'longitude' => 120.7620,
                'full_address' => '99 Another Street, Apalit',
            ])
            ->assertOk();

        $this->assertSame('2.90', $order->fresh()->delivery_distance_km);
    }

    public function test_the_address_locks_once_the_order_leaves_the_shop(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::ON_THE_WAY);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'latitude' => 14.9710,
                'longitude' => 120.7610,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ADDRESS_LOCKED');
    }

    public function test_a_pickup_order_has_no_address_to_change(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup');

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['latitude' => 14.97, 'longitude' => 120.76])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'ADDRESS_LOCKED');
    }

    // -----------------------------------------------------------------
    // The note
    // -----------------------------------------------------------------

    public function test_the_delivery_note_can_be_corrected(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::PREPARING);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['delivery_note' => 'Blue gate, ring twice'])
            ->assertOk()
            ->assertJsonPath('order.delivery.note', 'Blue gate, ring twice');
    }

    public function test_an_emptied_note_is_stored_as_nothing(): void
    {
        $order = $this->order($customer = $this->customer());
        $order->forceFill(['delivery_note' => 'Old note'])->save();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['delivery_note' => '   '])
            ->assertOk();

        $this->assertNull($order->fresh()->delivery_note);
    }

    public function test_the_note_locks_with_the_address(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::ON_THE_WAY);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['delivery_note' => 'Too late'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOTE_LOCKED');
    }

    // -----------------------------------------------------------------
    // The collection time
    // -----------------------------------------------------------------

    public function test_the_collection_time_can_be_moved(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup');
        $later = CarbonImmutable::now('Asia/Manila')->setTime(16, 30);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['pickup_at' => $later->toIso8601String()])
            ->assertOk();

        $this->assertSame(
            $later->toDateTimeString(),
            CarbonImmutable::parse($order->fresh()->pickup_at)->setTimezone('Asia/Manila')->toDateTimeString()
        );
    }

    public function test_a_collection_time_before_the_kitchen_can_manage_is_refused(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup');

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->addMinutes(2)->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PICKUP_TIME_TOO_SOON');
    }

    public function test_a_collection_time_after_closing_is_refused(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup');

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(23, 30)->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PICKUP_TIME_TOO_LATE');
    }

    public function test_the_collection_time_locks_once_the_food_is_waiting(): void
    {
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::READY_FOR_PICKUP);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->setTime(17, 0)->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PICKUP_TIME_LOCKED');
    }

    // -----------------------------------------------------------------
    // What is never amendable
    // -----------------------------------------------------------------

    public function test_the_fulfilment_type_and_the_items_are_never_open(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.editable.fulfilment_type', false)
            ->assertJsonPath('order.editable.items', false);
    }

    public function test_switching_to_pickup_is_not_a_field_the_endpoint_accepts(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['order_type' => 'pickup'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOTHING_TO_CHANGE');

        $this->assertSame('delivery', $order->fresh()->order_type);
    }

    public function test_a_finished_order_is_closed_to_changes(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::DELIVERED);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['delivery_note' => 'Anything'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Who may amend
    // -----------------------------------------------------------------

    public function test_a_stranger_cannot_amend_someone_elses_order(): void
    {
        $order = $this->order($this->customer());

        $this->actingAs($this->customer(), 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['delivery_note' => 'Not mine'])
            ->assertForbidden();
    }

    public function test_a_store_agent_can_amend_on_the_customers_behalf(): void
    {
        $order = $this->order($this->customer());

        // The phone-in case the whole policy leans on.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['delivery_note' => 'Customer called: leave with the guard'])
            ->assertOk();

        $this->assertSame('Customer called: leave with the guard', $order->fresh()->delivery_note);
    }

    public function test_a_guest_cannot_amend_anything(): void
    {
        $order = $this->order($this->customer());

        $this->patchJson("/api/orders/{$order->id}", ['delivery_note' => 'Hello'])->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // "These details are right - start cooking"
    // -----------------------------------------------------------------

    public function test_a_customer_can_close_their_own_change_window_early(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('order.can_confirm_details', true)
            ->assertJsonPath('order.details_confirmed_at', null);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-details")
            ->assertOk();

        $this->assertNotNull($order->fresh()->details_confirmed_at);
    }

    public function test_confirming_twice_is_refused_rather_than_re_stamped(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/confirm-details")->assertOk();
        $stamped = $order->fresh()->details_confirmed_at;

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-details")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOTHING_TO_CONFIRM');

        $this->assertEquals($stamped, $order->fresh()->details_confirmed_at);
    }

    public function test_there_is_nothing_to_confirm_once_the_kitchen_has_started(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::PREPARING);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-details")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOTHING_TO_CONFIRM');
    }

    public function test_amending_the_order_withdraws_the_confirmation(): void
    {
        $order = $this->order($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/confirm-details")->assertOk();
        $this->assertNotNull($order->fresh()->details_confirmed_at);

        // They have just changed the thing they confirmed, so the agent's
        // "safe to start" signal must stop being true.
        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", ['delivery_note' => 'Actually, the blue gate'])
            ->assertOk();

        $this->assertNull($order->fresh()->details_confirmed_at);
    }

    public function test_the_agents_queue_shows_which_orders_are_green_lit(): void
    {
        $order = $this->order($customer = $this->customer());
        $order->enterQueue();

        $this->actingAs($customer, 'sanctum')->postJson("/api/orders/{$order->id}/confirm-details")->assertOk();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->getJson('/api/agent/queue')
            ->assertOk()
            ->assertJsonPath('line.0.id', $order->id);

        $this->assertNotNull($order->fresh()->details_confirmed_at);
    }

    public function test_a_stranger_cannot_confirm_someone_elses_details(): void
    {
        $order = $this->order($this->customer());

        $this->actingAs($this->customer(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/confirm-details")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // What the tracker advertises
    // -----------------------------------------------------------------

    public function test_the_tracker_says_what_is_still_open(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::PREPARING);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.editable.address', true)
            ->assertJsonPath('order.editable.note', true)
            ->assertJsonPath('order.editable.pickup_at', false);
    }

    public function test_the_tracker_closes_everything_once_it_is_on_the_way(): void
    {
        $order = $this->order($customer = $this->customer(), 'delivery', OrderStatus::ON_THE_WAY);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.editable.address', false)
            ->assertJsonPath('order.editable.note', false);
    }

    // -----------------------------------------------------------------
    // Advance orders are not amendable here
    // -----------------------------------------------------------------
    //
    // `confirmed` exists in both chains, so without an explicit guard an
    // advance order would be offered the pickup-time editor - and the change
    // would be written to `pickup_at`, a column an advance order does not use
    // for its collection date. Rescheduling one is its own piece of work.

    private function advanceOrder(User $owner, string $status = OrderStatus::CONFIRMED): Order
    {
        $order = $this->order($owner, 'pickup', $status);

        $order->forceFill([
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDays(7)->setTime(12, 0),
        ])->save();

        return $order->fresh();
    }

    public function test_an_advance_order_offers_no_amendable_fields(): void
    {
        $order = $this->advanceOrder($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.is_advance', true)
            ->assertJsonPath('order.editable.pickup_at', false)
            ->assertJsonPath('order.editable.address', false)
            ->assertJsonPath('order.editable.note', false);
    }

    public function test_an_advance_order_is_not_asked_to_confirm_its_details(): void
    {
        // "Go ahead and start" means nothing on an order booked for next week.
        $order = $this->advanceOrder($customer = $this->customer());

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.can_confirm_details', false);
    }

    public function test_the_endpoint_refuses_to_amend_an_advance_order(): void
    {
        // Not merely hidden in the UI - the door itself is shut.
        $order = $this->advanceOrder($customer = $this->customer());
        $booked = $order->scheduled_for;

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/orders/{$order->id}", [
                'pickup_at' => CarbonImmutable::now('Asia/Manila')->addDays(7)->setTime(15, 0)->toIso8601String(),
            ])
            ->assertStatus(422);

        $this->assertTrue(
            $booked->equalTo($order->fresh()->scheduled_for),
            'the collection date must survive a refused amendment untouched'
        );
    }

    public function test_an_immediate_pickup_order_still_offers_its_collection_time(): void
    {
        // The guard must key on the order being an advance one, not on pickup.
        $order = $this->order($customer = $this->customer(), 'pickup', OrderStatus::CONFIRMED);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.is_advance', false)
            ->assertJsonPath('order.editable.pickup_at', true);
    }
}
