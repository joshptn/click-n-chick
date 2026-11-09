<?php

namespace Tests\Feature;

use App\Jobs\NotifyEveryAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\DeliveryPricing;
use App\Services\Orders\DeliveryQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Store Manager's delivery settings (BR-13, BR-14, UC-DEL-008, FR-06.5),
 * and that the rest of the system follows what is saved.
 */
class DeliverySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private const RATES = ['base_km' => 4, 'base_fee' => 60, 'extra_fee_per_km' => 15, 'max_driving_km' => 30];

    private function user(string $role): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->role = $role;
        $user->save();

        return $user->fresh();
    }

    private function save(array $rates)
    {
        return $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->putJson('/api/admin/settings/delivery', $rates + ['password' => self::PASSWORD]);
    }

    public function test_only_the_store_manager_can_read_or_change_store_settings(): void
    {
        Bus::fake();

        foreach ([User::ROLE_ADMIN, User::ROLE_CUSTOMER] as $role) {
            $actor = $this->user($role);

            foreach (['discount', 'delivery', 'store'] as $section) {
                $this->actingAs($actor, 'sanctum')->getJson("/api/admin/settings/{$section}")->assertForbidden();
            }

            $this->actingAs($actor, 'sanctum')
                ->putJson('/api/admin/settings/delivery', self::RATES + ['password' => self::PASSWORD])
                ->assertForbidden();
            $this->actingAs($actor, 'sanctum')
                ->putJson('/api/admin/settings/discount', ['percentage' => 25, 'password' => self::PASSWORD])
                ->assertForbidden();
            $this->actingAs($actor, 'sanctum')
                ->putJson('/api/admin/settings/store', ['opens_at' => '09:00', 'closes_at' => '18:00'])
                ->assertForbidden();
        }

        $this->assertSame(0, Setting::query()->count());
        Bus::assertNotDispatched(NotifyEveryAccount::class);
    }

    public function test_the_manager_reads_the_configured_limit_until_one_is_saved(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->getJson('/api/admin/settings/delivery')
            ->assertOk()
            ->assertJsonPath('max_driving_km', 45)
            ->assertJsonPath('defaults.max_driving_km', 45);
    }

    public function test_saved_rates_set_the_fee_and_every_account_is_told(): void
    {
        Bus::fake();

        $this->save(self::RATES)
            ->assertOk()
            ->assertJsonPath('base_km', 4)
            ->assertJsonPath('max_driving_km', 30);

        $pricing = app(DeliveryPricing::class);

        $this->assertSame(60.0, $pricing->feeFor(3.5));
        $this->assertSame(90.0, $pricing->feeFor(5.2));
        $this->assertSame(30.0, $pricing->maxDrivingKm());

        Bus::assertDispatchedTimes(NotifyEveryAccount::class, 1);
        Bus::assertDispatched(
            NotifyEveryAccount::class,
            fn (NotifyEveryAccount $job) => $job->title === 'Delivery fees updated'
                && $job->body === 'Delivery is now ₱60 for the first 4 km, then ₱15 for each further km, up to 30 km by road.'
        );
    }

    public function test_saving_the_same_rates_tells_nobody(): void
    {
        Bus::fake();

        $this->save([
            'base_km' => DeliveryPricing::DEFAULT_BASE_KM,
            'base_fee' => DeliveryPricing::DEFAULT_BASE_FEE,
            'extra_fee_per_km' => DeliveryPricing::DEFAULT_EXTRA_FEE_PER_KM,
            'max_driving_km' => 45,
        ])->assertOk();

        Bus::assertNotDispatched(NotifyEveryAccount::class);
    }

    public function test_the_limit_has_to_be_usable(): void
    {
        Bus::fake();

        foreach ([['max_driving_km' => 3], ['max_driving_km' => 0], ['max_driving_km' => 101], ['max_driving_km' => null]] as $bad) {
            $this->save($bad + self::RATES)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('max_driving_km');
        }

        $this->assertSame(45.0, app(DeliveryPricing::class)->maxDrivingKm());
        Bus::assertNotDispatched(NotifyEveryAccount::class);
    }

    public function test_a_saved_limit_is_what_checkout_and_the_public_status_use(): void
    {
        Bus::fake();
        $this->save(['max_driving_km' => 10] + self::RATES)->assertOk();

        // About 22 km north of the store: inside the old 45 km, outside this one.
        $origin = config('store.origin');
        $quote = app(DeliveryQuote::class)->for($origin['latitude'] + 0.2, $origin['longitude']);

        $this->assertFalse($quote['within_service_area']);
        $this->assertSame(10.0, $quote['max_driving_km']);
        $this->assertStringContainsString('10 km', $quote['message']);

        $this->getJson('/api/store/status')->assertOk()->assertJsonPath('max_driving_km', 10);
    }
}
