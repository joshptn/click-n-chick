<?php

namespace Tests\Feature;

use App\Events\MenuBroadcast;
use App\Models\Discount;
use App\Models\Food;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\DeviceRegistrar;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Orders\DeliveryPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Step-up re-authentication on privilege- and money-shaped admin actions.
 *
 * The device check makes a copied session hard to USE. This is the second half:
 * even a session presented from the right device cannot mint an admin or
 * reprice the menu without the account password, which a session thief does not
 * have.
 *
 * The tests that matter most are the negative ones - that the action really
 * does NOT happen when the password is absent or wrong. Asserting the 422 alone
 * would pass just as well against a controller that refused and saved anyway.
 */
class StepUpReauthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private function staff(string $role): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->role = $role;
        $user->save();

        return $user->fresh();
    }

    private function manager(): User
    {
        return $this->staff(User::ROLE_SUPER_ADMIN);
    }

    /**
     * A menu item.
     *
     * Fakes MenuBroadcast because editing a food publishes one, and the test
     * environment has no broadcaster listening. Only that event is faked, so
     * Eloquent's own model events - which Setting's cache invalidation hangs
     * off - keep firing normally.
     *
     * Prices are whole numbers because Food casts `price` to integer.
     */
    private function food(int $price = 100): Food
    {
        Event::fake([MenuBroadcast::class]);

        return Food::create([
            'food_name' => 'Chicken Bucket',
            'price' => $price,
            'description' => 'Six pieces.',
            'is_available' => true,
            'thumbnail' => 'https://res.cloudinary.com/demo/image/upload/bucket.jpg',
        ]);
    }

    /** The full valid payload for a food update, so only the price varies. */
    private function foodPayload(Food $food, array $overrides = []): array
    {
        return array_merge([
            'food_name' => $food->food_name,
            'price' => $food->price,
            'description' => $food->description,
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Role changes
    // -----------------------------------------------------------------

    public function test_a_role_change_without_a_password_is_refused_and_changes_nothing(): void
    {
        $actor = $this->manager();
        $target = $this->staff(User::ROLE_CUSTOMER);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", ['role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->assertSame(User::ROLE_CUSTOMER, $target->fresh()->role);
    }

    public function test_a_role_change_with_the_wrong_password_is_refused_and_changes_nothing(): void
    {
        $actor = $this->manager();
        $target = $this->staff(User::ROLE_CUSTOMER);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", [
                'role' => 'admin',
                'password' => 'not-the-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_INVALID);

        $this->assertSame(User::ROLE_CUSTOMER, $target->fresh()->role);
    }

    public function test_a_role_change_with_the_correct_password_succeeds(): void
    {
        $actor = $this->manager();
        $target = $this->staff(User::ROLE_CUSTOMER);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", [
                'role' => 'admin',
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(User::ROLE_ADMIN, $target->fresh()->role);
    }

    // -----------------------------------------------------------------
    // The statutory discount rate
    // -----------------------------------------------------------------

    public function test_a_discount_rate_change_without_a_password_is_refused_and_changes_nothing(): void
    {
        $before = Discount::currentPercentage();

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 45])
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->assertSame($before, Discount::currentPercentage());
    }

    public function test_a_discount_rate_change_with_the_password_succeeds(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/discount', [
                'percentage' => 45,
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(45.0, Discount::currentPercentage());
    }

    // -----------------------------------------------------------------
    // Delivery pricing
    // -----------------------------------------------------------------

    public function test_a_delivery_pricing_change_without_a_password_is_refused_and_changes_nothing(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/delivery', [
                'base_km' => 1,
                'base_fee' => 500,
                'extra_fee_per_km' => 99,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->assertSame(DeliveryPricing::DEFAULT_BASE_FEE, app(DeliveryPricing::class)->baseFee());
    }

    public function test_a_delivery_pricing_change_with_the_password_succeeds(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/delivery', [
                'base_km' => 5,
                'base_fee' => 70,
                'extra_fee_per_km' => 12,
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        $pricing = app(DeliveryPricing::class);

        $this->assertSame(5.0, $pricing->baseKm());
        $this->assertSame(70.0, $pricing->baseFee());
        $this->assertSame(12.0, $pricing->extraFeePerKm());
    }

    /** The rates are not decoration - the fee calculation reads them. */
    public function test_the_configured_rates_drive_the_delivery_fee(): void
    {
        $pricing = app(DeliveryPricing::class);

        // Defaults reproduce the previously hardcoded behaviour exactly.
        $this->assertSame(55.0, $pricing->feeFor(2.0));
        $this->assertSame(75.0, $pricing->feeFor(5.0));

        Setting::put(Setting::DELIVERY_BASE_KM, 1);
        Setting::put(Setting::DELIVERY_BASE_FEE, 100);
        Setting::put(Setting::DELIVERY_EXTRA_FEE_PER_KM, 20);

        $this->assertSame(100.0, $pricing->feeFor(1.0));
        $this->assertSame(140.0, $pricing->feeFor(3.0));
    }

    // -----------------------------------------------------------------
    // Loyalty rates
    // -----------------------------------------------------------------

    public function test_a_loyalty_rate_change_without_a_password_is_refused_and_changes_nothing(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/loyalty', [
                'points_per_peso' => 5,
                'peso_per_point' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->assertSame(0.0, Setting::number(Setting::LOYALTY_POINTS_PER_PESO, 0.0));
    }

    public function test_a_loyalty_rate_change_with_the_password_succeeds(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->putJson('/api/admin/settings/loyalty', [
                'points_per_peso' => 1.5,
                'peso_per_point' => 0.25,
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(1.5, Setting::number(Setting::LOYALTY_POINTS_PER_PESO, 0.0));
        $this->assertSame(0.25, Setting::number(Setting::LOYALTY_PESO_PER_POINT, 0.0));
    }

    // -----------------------------------------------------------------
    // Food prices - conditional, which is the interesting case
    // -----------------------------------------------------------------

    public function test_a_food_price_change_without_a_password_is_refused_and_changes_nothing(): void
    {
        $food = $this->food(100);

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/foods/{$food->id}", $this->foodPayload($food, ['price' => 1]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->assertSame(100, $food->fresh()->price);
    }

    public function test_a_food_price_change_with_the_password_succeeds(): void
    {
        $food = $this->food(100);

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/foods/{$food->id}", $this->foodPayload($food, [
                'price' => 125,
                'password' => self::PASSWORD,
            ]))
            ->assertOk();

        $this->assertSame(125, $food->fresh()->price);
    }

    /**
     * The point of doing this in the controller rather than as route
     * middleware: editing a description must not demand a password.
     */
    public function test_a_food_edit_that_leaves_the_price_alone_needs_no_password(): void
    {
        $food = $this->food(100);

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/foods/{$food->id}", $this->foodPayload($food, [
                'description' => 'Now with gravy.',
            ]))
            ->assertOk();

        $fresh = $food->fresh();

        $this->assertSame('Now with gravy.', $fresh->description);
        $this->assertSame(100, $fresh->price);
    }

    // -----------------------------------------------------------------
    // The confirmation window
    // -----------------------------------------------------------------

    public function test_one_confirmation_covers_further_actions_for_a_short_window(): void
    {
        $actor = $this->manager();
        $food = $this->food(100);

        // Confirm once, on a different action entirely.
        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/admin/settings/discount', [
                'percentage' => 30,
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        // No password this time.
        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/foods/{$food->id}", $this->foodPayload($food, ['price' => 120]))
            ->assertOk();

        $this->assertSame(120, $food->fresh()->price);
    }

    public function test_the_confirmation_window_lapses(): void
    {
        $actor = $this->manager();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/admin/settings/discount', [
                'percentage' => 30,
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->travel(PasswordConfirmation::WINDOW_MINUTES + 1)->minutes();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/admin/settings/discount', ['percentage' => 35])
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->assertSame(30.0, Discount::currentPercentage());
    }

    /**
     * A confirmation earned on one device must not carry to another.
     *
     * Otherwise the real owner confirming their password would hand a copied
     * session a free pass for the rest of the window.
     */
    public function test_a_confirmation_does_not_transfer_to_a_different_device(): void
    {
        $actor = $this->manager();

        $this->actingAs($actor, 'sanctum')
            ->withHeaders([DeviceRegistrar::HINT_HEADER => 'the-real-laptop'])
            ->putJson('/api/admin/settings/discount', [
                'percentage' => 30,
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->flushHeaders();

        $this->actingAs($actor, 'sanctum')
            ->withHeaders([DeviceRegistrar::HINT_HEADER => 'somewhere-else-entirely'])
            ->putJson('/api/admin/settings/discount', ['percentage' => 40])
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->assertSame(30.0, Discount::currentPercentage());
    }

    // -----------------------------------------------------------------
    // The oracle guard
    // -----------------------------------------------------------------

    /**
     * A password check reachable from an already-authenticated session is a
     * guessing oracle unless it is throttled.
     */
    public function test_repeated_wrong_passwords_are_throttled(): void
    {
        $actor = $this->manager();
        $target = $this->staff(User::ROLE_CUSTOMER);

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($actor, 'sanctum')
                ->patchJson("/api/admin/users/{$target->id}", [
                    'role' => 'admin',
                    'password' => 'wrong-every-time',
                ])
                ->assertStatus(422);
        }

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", [
                'role' => 'admin',
                'password' => 'wrong-every-time',
            ])
            ->assertStatus(429)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_THROTTLED);

        // And the correct password is locked out too, which is the point - the
        // budget is spent, not merely the wrong guesses.
        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", [
                'role' => 'admin',
                'password' => self::PASSWORD,
            ])
            ->assertStatus(429);

        $this->assertSame(User::ROLE_CUSTOMER, $target->fresh()->role);
    }

    public function test_a_correct_password_clears_the_wrong_attempt_budget(): void
    {
        $actor = $this->manager();
        $target = $this->staff(User::ROLE_CUSTOMER);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($actor, 'sanctum')
                ->patchJson("/api/admin/users/{$target->id}", [
                    'role' => 'admin',
                    'password' => 'mistyped',
                ])
                ->assertStatus(422);
        }

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", [
                'role' => 'admin',
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(User::ROLE_ADMIN, $target->fresh()->role);
    }

    // -----------------------------------------------------------------
    // Step-up does not replace the role gate
    // -----------------------------------------------------------------

    public function test_a_store_agent_with_the_right_password_still_cannot_change_roles(): void
    {
        $agent = $this->staff(User::ROLE_ADMIN);
        $target = $this->staff(User::ROLE_CUSTOMER);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}", [
                'role' => 'super_admin',
                'password' => self::PASSWORD,
            ])
            ->assertForbidden();

        $this->assertSame(User::ROLE_CUSTOMER, $target->fresh()->role);
    }
}
