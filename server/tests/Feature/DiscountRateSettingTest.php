<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The statutory discount rate as a Store-Manager-governed system setting
 * (FR-05.5, BR-10, BR-27, BR-34, UC-DISC-009, UC-ADMIN-007).
 */
class DiscountRateSettingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private int $phoneSeq = 0;

    private function user(string $role): User
    {
        $phone = '+6391715'.str_pad((string) (++$this->phoneSeq), 5, '0', STR_PAD_LEFT);

        return User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => 'email',
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    // -----------------------------------------------------------------
    // Reading the rate
    // -----------------------------------------------------------------

    public function test_the_rate_defaults_to_the_statutory_minimum_when_never_configured(): void
    {
        $this->assertSame(20.00, Discount::currentPercentage());
    }

    public function test_a_configured_rate_is_what_is_read_back(): void
    {
        Setting::put(Setting::DISCOUNT_PERCENTAGE, 25);

        $this->assertSame(25.00, Discount::currentPercentage());
    }

    /**
     * The floor is enforced on READ, not only on write.
     *
     * A seed, a migration, or a direct database edit never passes through the
     * controller's validation, so clamping only there would leave a path that
     * puts the business below the legal rate.
     */
    public function test_a_rate_written_below_the_floor_still_reads_as_the_floor(): void
    {
        Setting::query()->create(['key' => Setting::DISCOUNT_PERCENTAGE, 'value' => '5']);
        Setting::forget(Setting::DISCOUNT_PERCENTAGE);

        $this->assertSame(20.00, Discount::currentPercentage(), 'A sub-statutory rate escaped to the calculation.');
    }

    public function test_a_non_numeric_rate_falls_back_to_the_floor(): void
    {
        Setting::query()->create(['key' => Setting::DISCOUNT_PERCENTAGE, 'value' => 'twenty']);
        Setting::forget(Setting::DISCOUNT_PERCENTAGE);

        $this->assertSame(20.00, Discount::currentPercentage());
    }

    // -----------------------------------------------------------------
    // Who may change it
    // -----------------------------------------------------------------

    public function test_the_store_manager_can_change_the_rate(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 25, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('percentage', 25);

        $this->assertSame(25.00, Discount::currentPercentage());
    }

    public function test_a_store_agent_cannot_change_the_rate(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 30, 'password' => self::PASSWORD])
            ->assertStatus(403);

        $this->assertSame(20.00, Discount::currentPercentage());
    }

    public function test_a_customer_cannot_read_or_change_the_rate_setting(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/admin/settings/discount')
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 90, 'password' => self::PASSWORD])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // BR-34 - the 20% floor
    // -----------------------------------------------------------------

    public function test_a_rate_below_the_statutory_floor_is_refused(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 15, 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonValidationErrors('percentage');

        $this->assertSame(20.00, Discount::currentPercentage());
    }

    public function test_exactly_the_floor_is_accepted(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        Setting::put(Setting::DISCOUNT_PERCENTAGE, 30);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 20, 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertSame(20.00, Discount::currentPercentage());
    }

    public function test_a_rate_above_one_hundred_percent_is_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 150, 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonValidationErrors('percentage');
    }

    // -----------------------------------------------------------------
    // BR-27 - the change floats, and everyone is told
    // -----------------------------------------------------------------

    /**
     * The reason the per-claim column was dropped.
     *
     * A customer approved BEFORE the change must see the new rate, not the one
     * in force on the day their ID was accepted.
     */
    public function test_a_rate_change_reaches_a_customer_approved_before_it(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        Discount::create([
            'user_id' => $customer->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_APPROVED,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/discount')
            ->assertOk()
            ->assertJsonPath('percentage', 20);

        $this->app['auth']->forgetGuards();
        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 25, 'password' => self::PASSWORD])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/discount')
            ->assertOk()
            ->assertJsonPath('percentage', 25, 'The rate was frozen at approval time.');
    }

    public function test_the_profile_page_reports_the_live_rate_too(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        Setting::put(Setting::DISCOUNT_PERCENTAGE, 35);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('discount.percentage', 35);
    }

    public function test_every_user_is_notified_when_the_rate_changes(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);
        $agent = $this->user(User::ROLE_ADMIN);
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 25, 'password' => self::PASSWORD])
            ->assertOk();

        foreach ([$customer, $agent, $manager] as $user) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $user->id,
                'title' => 'Discount rate updated',
            ]);
        }
    }

    /** Re-saving the same value should not spam every account. */
    public function test_saving_an_unchanged_rate_notifies_nobody(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 20, 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['title' => 'Discount rate updated']);
    }

    public function test_the_change_records_who_made_it(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 25, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('updated_by.id', $manager->id);

        $this->assertDatabaseHas('settings', [
            'key' => Setting::DISCOUNT_PERCENTAGE,
            'updated_by' => $manager->id,
        ]);
    }

    /** The claim row must no longer carry a rate of its own. */
    public function test_a_claim_does_not_store_a_rate(): void
    {
        $this->assertNotContains(
            'discount_percentage',
            \Illuminate\Support\Facades\Schema::getColumnListing('discounts'),
            'The frozen per-claim rate is back.'
        );
    }
}
