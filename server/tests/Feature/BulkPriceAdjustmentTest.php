<?php

namespace Tests\Feature;

use App\Events\PriceBroadcast;
use App\Jobs\NotifyEveryAccount;
use App\Models\Category;
use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Store Manager's bulk price change, by a peso amount, on every dish or on
 * one category (FR-07.7, UC-ADMIN-005; decided 2026-11-24).
 */
class BulkPriceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Manager-pass-1';

    private Category $meals;

    private Category $drinks;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([PriceBroadcast::class]);

        $this->meals = Category::create(['name' => 'Chicken Meals']);
        $this->drinks = Category::create(['name' => 'Drinks']);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'password' => Hash::make(self::PASSWORD)]);
    }

    private function dish(string $name, int $price, ?Category $category = null, bool $available = true): Food
    {
        return Food::create([
            'thumbnail' => 'https://res.cloudinary.com/demo/image/upload/food/x.png',
            'food_name' => $name,
            'price' => $price,
            'description' => '',
            'is_available' => $available,
            'category_id' => ($category ?? $this->meals)->id,
        ]);
    }

    public function test_every_dish_goes_up_by_the_amount_archived_ones_too(): void
    {
        $spaghetti = $this->dish('Chicken Spaghetti', 180);
        $archived = $this->dish('Old Platter', 300, available: false);
        $iced = $this->dish('Iced Tea', 45, $this->drinks);
        $gone = $this->dish('Retired Wings', 200);
        $gone->delete();

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/foods/prices', ['amount' => 10, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('message', 'The prices of 3 dishes were changed.')
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.notice', 'Every dish on the menu is now ₱10 more.');

        $this->assertSame(190, $spaghetti->fresh()->price);
        $this->assertSame(310, $archived->fresh()->price);
        $this->assertSame(55, $iced->fresh()->price);
        $this->assertSame(200, Food::withTrashed()->find($gone->id)->price, 'A deleted dish is left alone.');

        // One notice and one live event for the whole change, not one per dish.
        Queue::assertPushed(NotifyEveryAccount::class, 1);
        Queue::assertPushed(
            NotifyEveryAccount::class,
            fn (NotifyEveryAccount $job) => $job->title === 'Price update' && $job->body === 'Every dish on the menu is now ₱10 more.'
        );
        Event::assertDispatchedTimes(PriceBroadcast::class, 1);
        Event::assertDispatched(PriceBroadcast::class, function (PriceBroadcast $event) use ($spaghetti, $archived, $iced) {
            $ids = $event->foodIds;
            sort($ids);
            $expected = [$spaghetti->id, $archived->id, $iced->id];
            sort($expected);

            return $ids === $expected && $event->broadcastAs() === 'prices';
        });
    }

    public function test_one_category_goes_down_and_the_rest_stay(): void
    {
        $spaghetti = $this->dish('Chicken Spaghetti', 180);
        $iced = $this->dish('Iced Tea', 45, $this->drinks);
        $lemonade = $this->dish('Lemonade', 50, $this->drinks);

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/foods/prices', ['amount' => -5, 'category_id' => $this->drinks->id, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.category.name', 'Drinks')
            ->assertJsonPath('data.notice', 'Every dish in Drinks is now ₱5 less.');

        $this->assertSame(180, $spaghetti->fresh()->price);
        $this->assertSame(40, $iced->fresh()->price);
        $this->assertSame(45, $lemonade->fresh()->price);

        Queue::assertPushed(NotifyEveryAccount::class, fn ($job) => $job->body === 'Every dish in Drinks is now ₱5 less.');
    }

    public function test_the_preview_shows_each_new_price_and_changes_nothing(): void
    {
        $spaghetti = $this->dish('Chicken Spaghetti', 180);

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/foods/prices/preview', ['amount' => 20])
            ->assertOk()
            ->assertJsonPath('data.can_apply', true)
            ->assertJsonPath('data.foods.0.food_name', 'Chicken Spaghetti')
            ->assertJsonPath('data.foods.0.previous', 180)
            ->assertJsonPath('data.foods.0.price', 200)
            ->assertJsonPath('data.notice', 'Every dish on the menu is now ₱20 more.');

        $this->assertSame(180, $spaghetti->fresh()->price);
        Queue::assertNotPushed(NotifyEveryAccount::class);
        Event::assertNotDispatched(PriceBroadcast::class);
    }

    public function test_it_asks_for_the_password_first(): void
    {
        $spaghetti = $this->dish('Chicken Spaghetti', 180);
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/foods/prices', ['amount' => 10])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->postJson('/api/foods/prices', ['amount' => 10, 'password' => 'wrong-one'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_INVALID');

        $this->assertSame(180, $spaghetti->fresh()->price);
        Queue::assertNotPushed(NotifyEveryAccount::class);
    }

    public function test_a_dish_that_would_go_below_zero_stops_the_whole_change(): void
    {
        $spaghetti = $this->dish('Chicken Spaghetti', 180);
        $rice = $this->dish('Plain Rice', 25);

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/foods/prices', ['amount' => -30, 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PRICE_OUT_OF_RANGE')
            ->assertJsonPath('message', 'That would take Plain Rice below ₱0. Nothing was changed.')
            ->assertJsonPath('data.out_of_range.0.food_name', 'Plain Rice')
            ->assertJsonPath('data.out_of_range.0.price', -5);

        $this->assertSame(180, $spaghetti->fresh()->price);
        $this->assertSame(25, $rice->fresh()->price);
        Queue::assertNotPushed(NotifyEveryAccount::class);
        Event::assertNotDispatched(PriceBroadcast::class);
    }

    public function test_a_dish_that_would_pass_the_highest_price_stops_it_too(): void
    {
        $this->dish('Party Tray', 99990);

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/foods/prices', ['amount' => 10, 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PRICE_OUT_OF_RANGE')
            ->assertJsonPath('message', 'That would take Party Tray above ₱99,999. Nothing was changed.');
    }

    public function test_an_empty_category_has_nothing_to_change(): void
    {
        $this->dish('Chicken Spaghetti', 180);
        $empty = Category::create(['name' => 'Desserts']);

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/foods/prices', ['amount' => 10, 'category_id' => $empty->id, 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'NO_DISHES')
            ->assertJsonPath('message', 'There are no dishes in Desserts to change.');

        Queue::assertNotPushed(NotifyEveryAccount::class);
    }

    public function test_the_amount_is_whole_pesos_and_not_zero(): void
    {
        $this->dish('Chicken Spaghetti', 180);
        $manager = $this->manager();

        foreach ([0, 10.5, 'ten', null] as $amount) {
            $this->actingAs($manager, 'sanctum')
                ->postJson('/api/foods/prices/preview', ['amount' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        $this->postJson('/api/foods/prices/preview', ['amount' => 10, 'category_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_only_the_store_manager_can_change_prices(): void
    {
        $spaghetti = $this->dish('Chicken Spaghetti', 180);

        foreach ([User::ROLE_ADMIN, User::ROLE_CUSTOMER] as $role) {
            $user = User::factory()->create(['role' => $role, 'password' => Hash::make(self::PASSWORD)]);

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/foods/prices', ['amount' => 10, 'password' => self::PASSWORD])
                ->assertForbidden();

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/foods/prices/preview', ['amount' => 10])
                ->assertForbidden();
        }

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/foods/prices', ['amount' => 10])->assertUnauthorized();

        $this->assertSame(180, $spaghetti->fresh()->price);
    }
}
