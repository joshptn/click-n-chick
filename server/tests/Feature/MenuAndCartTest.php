<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Food;
use App\Models\Poster;
use App\Models\User;
use App\Services\Media\CloudinaryService;
use App\Services\Verification\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The customer home page: menu reads, filtering, stock states and the cart.
 *
 * No image is ever uploaded here - Cloudinary is unconfigured under test, and
 * these assert the application's own behaviour rather than the provider's.
 */
class MenuAndCartTest extends TestCase
{
    use RefreshDatabase;

    /** Distinct per call: phone_number_hash is uniquely indexed. */
    private int $phoneSeq = 0;

    private function customer(): User
    {
        $phone = '+63917000'.str_pad((string) (++$this->phoneSeq), 4, '0', STR_PAD_LEFT);

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

    private function food(array $overrides = []): Food
    {
        return Food::create(array_merge([
            'food_name' => 'Chicken Inasal',
            'description' => 'Charcoal grilled.',
            'price' => 160,
            'thumbnail' => 'https://example.test/inasal.jpg',
            'stock_quantity' => 10,
            'is_available' => true,
            'is_best_seller' => false,
            'prep_time' => 15,
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // Orderability - the single source the UI greys out on
    // -----------------------------------------------------------------

    public function test_stock_status_distinguishes_untracked_from_sold_out(): void
    {
        // Null is "the kitchen makes it to order", NOT zero. Collapsing the two
        // would grey out every made-to-order dish.
        $this->assertSame(Food::STOCK_UNTRACKED, $this->food(['stock_quantity' => null])->stock_status);
        $this->assertTrue($this->food(['stock_quantity' => null])->is_orderable);

        $this->assertSame(Food::STOCK_OUT, $this->food(['stock_quantity' => 0])->stock_status);
        $this->assertFalse($this->food(['stock_quantity' => 0])->is_orderable);

        $this->assertSame(Food::STOCK_LOW, $this->food(['stock_quantity' => 3])->stock_status);
        $this->assertSame(Food::STOCK_IN, $this->food(['stock_quantity' => 40])->stock_status);
    }

    public function test_an_item_switched_off_by_the_store_agent_is_not_orderable_even_with_stock(): void
    {
        $food = $this->food(['is_available' => false, 'stock_quantity' => 50]);

        $this->assertFalse($food->is_orderable);
    }

    // -----------------------------------------------------------------
    // The menu endpoint
    // -----------------------------------------------------------------

    public function test_the_menu_is_public_and_reports_orderability(): void
    {
        $this->food(['food_name' => 'Sold Out Dish', 'stock_quantity' => 0]);

        $response = $this->getJson('/api/foods')->assertOk();

        $this->assertSame(Food::LOW_STOCK_THRESHOLD, $response->json('meta.low_stock_threshold'));
        $this->assertFalse($response->json('data.0.is_orderable'));
        $this->assertSame('out_of_stock', $response->json('data.0.stock_status'));
    }

    public function test_sold_out_items_are_kept_in_the_list_but_sorted_last(): void
    {
        $this->food(['food_name' => 'AAA Sold Out', 'stock_quantity' => 0]);
        $this->food(['food_name' => 'ZZZ In Stock', 'stock_quantity' => 9]);

        $names = collect($this->getJson('/api/foods')->assertOk()->json('data'))->pluck('food_name');

        // Alphabetically AAA would lead; orderability outranks the name.
        $this->assertSame('ZZZ In Stock', $names->first());
        $this->assertSame('AAA Sold Out', $names->last());
    }

    public function test_the_menu_filters_by_category_name_and_by_id(): void
    {
        $combos = Category::create(['name' => 'Combos']);
        $drinks = Category::create(['name' => 'Drinks']);

        $this->food(['food_name' => 'Inasal Combo', 'category_id' => $combos->id]);
        $this->food(['food_name' => 'Iced Tea', 'category_id' => $drinks->id]);

        $this->getJson('/api/foods?category=Combos')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.food_name', 'Inasal Combo');

        $this->getJson('/api/foods?category='.$drinks->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.food_name', 'Iced Tea');

        // 'all' is the UI's pseudo-category and must not filter anything out.
        $this->getJson('/api/foods?category=all')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_search_matches_name_and_description_and_escapes_wildcards(): void
    {
        $this->food(['food_name' => 'Chicken Inasal', 'description' => 'Grilled over charcoal.']);
        $this->food(['food_name' => 'Buffalo Wings', 'description' => 'Tangy and hot.']);

        $this->getJson('/api/foods?search=inasal')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/foods?search=charcoal')->assertOk()->assertJsonCount(1, 'data');

        // A bare % must be a literal, not "match everything".
        $this->getJson('/api/foods?search=%')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_best_seller_filter_backs_the_popular_dishes_rail(): void
    {
        $this->food(['food_name' => 'Popular', 'is_best_seller' => true]);
        $this->food(['food_name' => 'Ordinary', 'is_best_seller' => false]);

        $this->getJson('/api/foods?best_seller=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.food_name', 'Popular');
    }

    public function test_the_detail_endpoint_groups_addons_the_way_the_modal_renders_them(): void
    {
        $food = $this->food();

        $tea = Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => 65, 'addon_group' => 'Drinks']);
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides']);
        $off = Addon::create(['addon_name' => 'Retired', 'addon_price' => 10, 'addon_group' => 'Sides', 'availability' => false]);

        $food->addons()->sync([$tea->id, $fries->id, $off->id]);

        $groups = collect($this->getJson("/api/foods/{$food->id}")->assertOk()->json('data.addon_groups'));

        $this->assertEqualsCanonicalizing(['Drinks', 'Sides'], $groups->pluck('group')->all());

        // An add-on switched off must not be offered.
        $names = $groups->flatMap(fn ($group) => collect($group['addons'])->pluck('addon_name'))->all();
        $this->assertNotContains('Retired', $names);
    }

    // -----------------------------------------------------------------
    // Posters
    // -----------------------------------------------------------------

    public function test_only_visible_posters_are_published_and_they_respect_sort_order(): void
    {
        Poster::create(['poster_name' => 'Second', 'image' => 'a.jpg', 'sort_order' => 2]);
        Poster::create(['poster_name' => 'First', 'image' => 'b.jpg', 'sort_order' => 1]);
        Poster::create(['poster_name' => 'Hidden', 'image' => 'c.jpg', 'is_active' => false]);
        Poster::create(['poster_name' => 'Expired', 'image' => 'd.jpg', 'expires_at' => now()->subDay()]);

        $names = collect($this->getJson('/api/posters')->assertOk()->json('data'))->pluck('poster_name');

        $this->assertSame(['First', 'Second'], $names->all());
    }

    public function test_a_poster_expiring_today_is_still_shown_today(): void
    {
        Poster::create(['poster_name' => 'Last Day', 'image' => 'a.jpg', 'expires_at' => now()]);

        $this->getJson('/api/posters')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_managing_posters_is_store_manager_only(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/posters')->assertStatus(403);

        $agent = $this->customer();
        $agent->role = User::ROLE_ADMIN;
        $agent->save();

        // BR-29 / FR-07.6: the Store Agent is fenced out of catalogue work.
        $this->actingAs($agent->fresh(), 'sanctum')->getJson('/api/admin/posters')->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // The cart
    // -----------------------------------------------------------------

    public function test_the_cart_requires_a_signed_in_customer(): void
    {
        $this->getJson('/api/cart')->assertStatus(401);
    }

    public function test_adding_an_item_prices_it_with_its_addons(): void
    {
        $user = $this->customer();
        $food = $this->food(['price' => 180]);

        $tea = Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => 65, 'addon_group' => 'Drinks']);
        $food->addons()->sync([$tea->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/cart/items', [
            'food_id' => $food->id,
            'quantity' => 2,
            'addon_ids' => [$tea->id],
        ])->assertStatus(201);

        // (180 + 65) * 2. Priced by the server, never sent by the client.
        $this->assertEquals(490, $response->json('subtotal'));
        $this->assertSame(2, $response->json('item_count'));
    }

    public function test_a_sold_out_item_is_refused_even_though_the_button_is_disabled(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 0]);

        $this->actingAs($user, 'sanctum')->postJson('/api/cart/items', ['food_id' => $food->id])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'FOOD_UNAVAILABLE');

        $this->actingAs($user, 'sanctum')->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'cart');
    }

    public function test_lines_merge_on_matching_addons_and_stay_separate_otherwise(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 50]);

        $tea = Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => 65, 'addon_group' => 'Drinks']);
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides']);
        $food->addons()->sync([$tea->id, $fries->id]);

        $add = fn (array $addonIds) => $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'addon_ids' => $addonIds]);

        $add([$tea->id])->assertStatus(201);
        // Same set, submitted in the other order: must merge, not split.
        $add([$tea->id])->assertStatus(201);
        $response = $add([$fries->id])->assertStatus(201);

        $this->assertSame(2, $response->json('line_count'), 'Different add-ons are different lines.');
        $this->assertSame(3, $response->json('item_count'));
    }

    public function test_quantity_is_absolute_and_zero_removes_the_line(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 50]);

        $added = $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 2])->assertStatus(201);

        $lineId = $added->json('cart_item_id');

        $this->actingAs($user, 'sanctum')->patchJson("/api/cart/items/{$lineId}", ['quantity' => 5])
            ->assertOk()->assertJsonPath('item_count', 5);

        $this->actingAs($user, 'sanctum')->patchJson("/api/cart/items/{$lineId}", ['quantity' => 0])
            ->assertOk()->assertJsonPath('item_count', 0)->assertJsonCount(0, 'cart');
    }

    public function test_quantity_is_clamped_to_available_stock(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 3]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id, 'quantity' => 99])
            ->assertStatus(201)
            ->assertJsonPath('item_count', 3);
    }

    public function test_one_customer_cannot_touch_another_customers_line(): void
    {
        $owner = $this->customer();
        $food = $this->food();

        $lineId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $food->id])->json('cart_item_id');

        $intruder = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/cart/items/{$lineId}", ['quantity' => 99])->assertStatus(403);

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/cart/items/{$lineId}")->assertStatus(403);
    }

    public function test_an_item_that_sells_out_after_being_added_is_flagged_not_silently_dropped(): void
    {
        $user = $this->customer();
        $food = $this->food(['stock_quantity' => 5]);

        $this->actingAs($user, 'sanctum')->postJson('/api/cart/items', ['food_id' => $food->id])->assertStatus(201);

        $food->update(['stock_quantity' => 0]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/cart')->assertOk();

        $this->assertTrue($response->json('has_unavailable_items'));
        $this->assertFalse($response->json('cart.0.is_orderable'));
        $this->assertCount(1, $response->json('cart'), 'The line stays so the customer can see what happened.');
    }

    public function test_clearing_empties_the_cart(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cart/items', ['food_id' => $this->food()->id])->assertStatus(201);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/cart')
            ->assertOk()->assertJsonPath('item_count', 0);
    }

    // -----------------------------------------------------------------
    // Cloudinary wiring
    // -----------------------------------------------------------------

    public function test_cloudinary_reads_credentials_from_a_single_url(): void
    {
        // The form Cloudinary's dashboard hands you. The previous
        // implementation read only the three discrete vars, so setting this
        // alone left every upload unauthenticated.
        config()->set('cloudinary.cloud_url', 'cloudinary://123456789:sekrit@my-cloud');

        $cloudinary = new CloudinaryService();

        $this->assertTrue($cloudinary->isConfigured());
        $this->assertSame('my-cloud', $cloudinary->credentials()['cloud_name']);
        $this->assertSame('123456789', $cloudinary->credentials()['api_key']);
    }

    public function test_cloudinary_falls_back_to_the_discrete_variables(): void
    {
        config()->set('cloudinary.cloud_url', 'cloudinary://:@');
        config()->set('filesystems.disks.cloudinary.cloud', 'my-cloud');
        config()->set('filesystems.disks.cloudinary.key', '123');
        config()->set('filesystems.disks.cloudinary.secret', 'sekrit');

        $this->assertTrue((new CloudinaryService())->isConfigured());
    }

    public function test_cloudinary_reports_itself_unconfigured_rather_than_half_working(): void
    {
        config()->set('cloudinary.cloud_url', 'cloudinary://:@');
        config()->set('filesystems.disks.cloudinary.cloud', null);

        $cloudinary = new CloudinaryService();

        $this->assertFalse($cloudinary->isConfigured());
        $this->assertStringContainsString('CLOUDINARY_URL', $cloudinary->unavailableReason());
    }
}
