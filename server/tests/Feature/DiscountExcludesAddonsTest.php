<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Discount;
use App\Models\Food;
use App\Models\User;
use App\Services\Verification\Channel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BR-10 - the statutory discount comes off the base food items only. Not the
 * delivery fee, and not the add-ons.
 */
class DiscountExcludesAddonsTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Manila')->utc());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function customer(): User
    {
        $phone = '+63922000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

        $user = User::factory()->create([
            'first_name' => 'Senior',
            'last_name' => 'Customer',
            'role' => User::ROLE_CUSTOMER,
            'password' => Hash::make('Password123!'),
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            'verification_channel' => Channel::Email->value,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();

        Discount::create([
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_APPROVED,
            'verified_at' => now(),
        ]);

        return $user;
    }

    /** A ₱200 dish with a ₱50 add-on: ₱250 a line, but only ₱200 is discountable. */
    private function foodWithAddon(): array
    {
        $food = Food::create([
            'food_name' => 'Chicken Inasal',
            'description' => 'Charcoal grilled.',
            'price' => 200,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 20,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 15,
        ]);

        $addon = Addon::create([
            'addon_name' => 'Extra rice',
            'addon_price' => 50,
            'availability' => true,
        ]);

        $food->addons()->attach($addon->id);

        return [$food, $addon];
    }

    public function test_an_advance_order_discounts_the_food_but_not_the_addon(): void
    {
        $user = $this->customer();
        [$food, $addon] = $this->foodWithAddon();

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 2,
            'addon_ids' => [$addon->id],
            'mode' => 'advance',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/advance-orders/quote', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String(),
            'apply_discount' => true,
        ])->assertOk()
            // Two lines at 250 = 500 payable before the discount...
            ->assertJsonPath('subtotal', 500)
            // ...but only the 400 of base food is discountable.
            ->assertJsonPath('discount_base', 400)
            ->assertJsonPath('discount.amount', 80)
            ->assertJsonPath('total', 420);
    }

    public function test_an_immediate_order_discounts_the_food_but_not_the_addon(): void
    {
        $user = $this->customer();
        [$food, $addon] = $this->foodWithAddon();

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 2,
            'addon_ids' => [$addon->id],
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/checkout/quote', [
            'fulfilment_type' => 'pickup',
            'pickup_at' => CarbonImmutable::now('Asia/Manila')->addHour()->toIso8601String(),
            'apply_discount' => true,
        ])->assertOk()
            ->assertJsonPath('subtotal', 500)
            ->assertJsonPath('discount_base', 400)
            ->assertJsonPath('discount.amount', 80);
    }

    public function test_without_addons_the_discount_base_is_the_whole_subtotal(): void
    {
        $user = $this->customer();
        [$food] = $this->foodWithAddon();

        $this->actingAs($user)->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 3,
            'mode' => 'advance',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/advance-orders/quote', [
            'scheduled_for' => CarbonImmutable::now('Asia/Manila')->addDay()->setTime(12, 0)->toIso8601String(),
            'apply_discount' => true,
        ])->assertOk()
            ->assertJsonPath('subtotal', 600)
            ->assertJsonPath('discount_base', 600)
            ->assertJsonPath('discount.amount', 120);
    }
}
