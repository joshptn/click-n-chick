<?php

namespace Tests\Feature;

use App\Jobs\NotifyEveryAccount;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Food;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Media\CloudinaryService;
use App\Services\Orders\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Store Manager's catalogue: dishes and categories (BR-29, UC-MENU-007..014).
 */
class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Manager-pass-1';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->mock(CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('upload')->andReturn('https://res.cloudinary.com/demo/image/upload/food/new.png')->byDefault();
            $mock->shouldReceive('delete')->andReturnNull()->byDefault();
        });
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'password' => Hash::make(self::PASSWORD)]);
    }

    private function category(string $name = 'Chicken'): Category
    {
        return Category::create(['name' => $name]);
    }

    private function food(array $attributes = []): Food
    {
        return Food::create($attributes + [
            'thumbnail' => 'https://res.cloudinary.com/demo/image/upload/food/old.png',
            'food_name' => 'Spicy Fried Chicken',
            'price' => 249,
            'description' => 'Two pieces.',
            'is_available' => true,
            'category_id' => $this->category()->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'food_name' => 'Spicy Fried Chicken',
            'price' => 249,
            'description' => 'Two pieces.',
            'category_id' => Category::query()->value('id') ?? $this->category()->id,
        ];
    }

    // -----------------------------------------------------------------
    // Adding and editing
    // -----------------------------------------------------------------

    public function test_the_manager_adds_a_dish_with_a_photo(): void
    {
        $category = $this->category('Chicken');

        $this->actingAs($this->manager(), 'sanctum')
            ->post('/api/foods', $this->payload([
                'food_name' => 'Chicken Fingers',
                'price' => 150,
                'description' => '',
                'category_id' => $category->id,
                'thumbnail' => UploadedFile::fake()->image('fingers.png'),
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.food_name', 'Chicken Fingers')
            ->assertJsonPath('data.price', 150)
            ->assertJsonPath('data.category.name', 'Chicken')
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.thumbnail', 'https://res.cloudinary.com/demo/image/upload/food/new.png');

        $this->assertDatabaseHas('food', ['food_name' => 'Chicken Fingers', 'description' => '', 'stock_quantity' => null]);
    }

    public function test_a_new_dish_needs_a_photo_a_category_and_a_whole_peso_price(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/foods', ['food_name' => 'Rice', 'price' => 39.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['thumbnail', 'category_id', 'price']);
    }

    public function test_two_dishes_cannot_share_a_name(): void
    {
        $this->food(['food_name' => 'Chicken Fingers']);

        $this->actingAs($this->manager(), 'sanctum')
            ->post('/api/foods', $this->payload([
                'food_name' => '  chicken fingers ',
                'thumbnail' => UploadedFile::fake()->image('dish.png'),
            ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('food_name');
    }

    public function test_a_failed_upload_saves_nothing(): void
    {
        $this->mock(CloudinaryService::class, fn ($mock) => $mock->shouldReceive('upload')->andThrow(new \RuntimeException('down')));

        $this->actingAs($this->manager(), 'sanctum')
            ->post('/api/foods', $this->payload([
                'food_name' => 'Liempo',
                'thumbnail' => UploadedFile::fake()->image('dish.png'),
            ]), ['Accept' => 'application/json'])
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'IMAGE_UPLOAD_FAILED');

        $this->assertDatabaseMissing('food', ['food_name' => 'Liempo']);
    }

    public function test_editing_without_a_price_change_needs_no_password_and_tells_nobody(): void
    {
        $food = $this->food();

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/foods/{$food->id}", $this->payload(['food_name' => 'Extra Spicy Fried Chicken', 'description' => null]))
            ->assertOk()
            ->assertJsonPath('data.food_name', 'Extra Spicy Fried Chicken')
            ->assertJsonPath('data.thumbnail', 'https://res.cloudinary.com/demo/image/upload/food/old.png');

        Queue::assertNotPushed(NotifyEveryAccount::class);
    }

    public function test_a_price_change_asks_for_the_password_then_notifies_every_account(): void
    {
        $food = $this->food();
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/foods/{$food->id}", $this->payload(['price' => 259]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->assertSame(249, $food->fresh()->price);

        $this->putJson("/api/foods/{$food->id}", $this->payload(['price' => 259, 'password' => 'wrong-one']))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_INVALID');

        $this->putJson("/api/foods/{$food->id}", $this->payload(['price' => 259, 'password' => self::PASSWORD]))
            ->assertOk()
            ->assertJsonPath('data.price', 259);

        Queue::assertPushed(
            NotifyEveryAccount::class,
            fn (NotifyEveryAccount $job) => $job->body === 'Spicy Fried Chicken is now ₱259, changed from ₱249.'
        );
    }

    public function test_a_new_photo_replaces_the_old_one_only_after_it_uploads(): void
    {
        $food = $this->food();

        $this->mock(CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('upload')->once()->andReturn('https://res.cloudinary.com/demo/image/upload/food/fresh.png');
            $mock->shouldReceive('delete')->once()->with('https://res.cloudinary.com/demo/image/upload/food/old.png', 'food');
        });

        $this->actingAs($this->manager(), 'sanctum')
            ->post("/api/foods/{$food->id}", $this->payload([
                '_method' => 'PUT',
                'thumbnail' => UploadedFile::fake()->image('fresh.png'),
            ]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.thumbnail', 'https://res.cloudinary.com/demo/image/upload/food/fresh.png');
    }

    // -----------------------------------------------------------------
    // Archiving (the availability switch) and deleting
    // -----------------------------------------------------------------

    public function test_archiving_takes_a_dish_off_the_menu_and_unarchiving_puts_it_back(): void
    {
        $food = $this->food();
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/foods/{$food->id}/archive", ['archived' => true])
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.is_orderable', false);

        $this->assertNotContains($food->id, collect($this->getJson('/api/foods?orderable=1')->json('data'))->pluck('id'));

        $this->patchJson("/api/foods/{$food->id}/archive", ['archived' => false])
            ->assertOk()
            ->assertJsonPath('data.is_available', true);
    }

    public function test_deleting_keeps_past_orders_whole_and_empties_carts(): void
    {
        $food = $this->food(['food_name' => 'Chicken Fingers']);
        $customer = User::factory()->create();

        $order = Order::create([
            'user_id' => $customer->id,
            'order_type' => 'pickup',
            'status' => OrderStatus::PLACED,
            'subtotal' => 249,
            'total_amount' => 249,
            'total_price' => 249,
        ]);
        OrderItem::create(['order_id' => $order->id, 'food_id' => $food->id, 'quantity' => 1, 'price' => 249, 'unit_price' => 249, 'subtotal' => 249]);
        $order->forceFill(['status' => OrderStatus::COMPLETED])->save();

        $cart = Cart::create(['user_id' => $customer->id]);
        CartItem::create(['cart_id' => $cart->id, 'food_id' => $food->id, 'quantity' => 2]);

        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/foods/{$food->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Chicken Fingers was deleted from the catalog.');

        $this->assertSoftDeleted('food', ['id' => $food->id]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'food_id' => $food->id]);
        $this->assertDatabaseMissing('cart_items', ['food_id' => $food->id]);

        // Gone from the menu and the catalogue...
        $this->assertNotContains($food->id, collect($this->getJson('/api/foods')->json('data'))->pluck('id'));
        $this->getJson("/api/foods/{$food->id}")->assertNotFound();

        // ...still named on the order it was sold in.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), 'sanctum')
            ->getJson("/api/agent/records/{$order->id}")
            ->assertJsonPath('data.items.0.food_name', 'Chicken Fingers');

        // A deleted dish cannot be edited, archived or deleted twice.
        $this->actingAs($manager, 'sanctum')->deleteJson("/api/foods/{$food->id}")->assertNotFound();
        $this->patchJson("/api/foods/{$food->id}/archive", ['archived' => true])->assertNotFound();
    }

    public function test_a_deleted_name_can_be_used_again(): void
    {
        $old = $this->food(['food_name' => 'Chicken Fingers']);
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')->deleteJson("/api/foods/{$old->id}")->assertOk();

        $this->post('/api/foods', $this->payload([
            'food_name' => 'Chicken Fingers',
            'thumbnail' => UploadedFile::fake()->image('dish.png'),
        ]), ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_the_store_agent_cannot_archive_edit_or_delete(): void
    {
        $food = $this->food();
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($agent, 'sanctum')->patchJson("/api/foods/{$food->id}/archive", ['archived' => true])->assertForbidden();
        $this->putJson("/api/foods/{$food->id}", $this->payload(['price' => 1]))->assertForbidden();
        $this->deleteJson("/api/foods/{$food->id}")->assertForbidden();

        $this->assertTrue($food->fresh()->is_available);
        $this->assertSame(249, $food->fresh()->price);
    }

    // -----------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------

    public function test_categories_are_added_and_renamed_without_duplicates(): void
    {
        $manager = $this->manager();

        $id = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/category', ['name' => '  Rice   Meals '])
            ->assertCreated()
            ->assertJsonPath('name', 'Rice Meals')
            ->json('id');

        $this->postJson('/api/category', ['name' => 'rice meals'])->assertJsonValidationErrors('name');

        $this->putJson("/api/category/{$id}", ['name' => 'Rice Bowls'])->assertOk()->assertJsonPath('name', 'Rice Bowls');
        // Renaming to its own name, in another case, is not a duplicate of itself.
        $this->putJson("/api/category/{$id}", ['name' => 'RICE BOWLS'])->assertOk();
    }

    public function test_only_an_empty_category_can_be_deleted(): void
    {
        $manager = $this->manager();
        $full = $this->category('Combos');
        $archived = $this->food(['food_name' => 'Family Tray', 'category_id' => $full->id, 'is_available' => false]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/category/{$full->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CATEGORY_NOT_EMPTY')
            ->assertJsonPath('dishes', 1);

        // A deleted dish no longer holds its category.
        $this->deleteJson("/api/foods/{$archived->id}")->assertOk();
        $this->deleteJson("/api/category/{$full->id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $full->id]);
    }

    public function test_the_store_agent_cannot_manage_categories(): void
    {
        $category = $this->category();
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($agent, 'sanctum')->putJson("/api/category/{$category->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/category/{$category->id}")->assertForbidden();
    }
}
