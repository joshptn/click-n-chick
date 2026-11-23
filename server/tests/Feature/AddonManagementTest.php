<?php

namespace Tests\Feature;

use App\Events\AddonBroadcast;
use App\Events\PriceBroadcast;
use App\Jobs\NotifyEveryAccount;
use App\Models\Addon;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Food;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Media\CloudinaryService;
use App\Services\Orders\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Add-ons in the catalogue (BR-17; decided 2026-11-24): the Store Manager
 * adds, edits, prices, archives and deletes them and picks the dishes that
 * offer them; the Store Agent switches an add-on, or one of its sizes, on and
 * off.
 */
class AddonManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Manager-pass-1';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([AddonBroadcast::class, PriceBroadcast::class]);

        $this->mock(CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('upload')->andReturn('https://res.cloudinary.com/demo/image/upload/food/new.png')->byDefault();
            $mock->shouldReceive('delete')->andReturnNull()->byDefault();
        });
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'password' => Hash::make(self::PASSWORD)]);
    }

    private function agent(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function food(string $name = 'Chicken Inasal', int $price = 200): Food
    {
        return Food::create([
            'thumbnail' => 'https://res.cloudinary.com/demo/image/upload/food/old.png',
            'food_name' => $name,
            'price' => $price,
            'description' => 'Grilled.',
            'is_available' => true,
            'category_id' => (Category::query()->first() ?? Category::create(['name' => 'Chicken']))->id,
        ]);
    }

    private function sizedTea(array $sizes = ['small' => 45, 'medium' => 55, 'large' => 65]): Addon
    {
        $tea = Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => min($sizes), 'addon_group' => 'Drinks']);

        foreach ($sizes as $size => $price) {
            $tea->sizes()->create(['size' => $size, 'price' => $price]);
        }

        return $tea;
    }

    /** The dish's add-ons as a customer is offered them. */
    private function offered(Food $food): array
    {
        return collect($this->getJson("/api/foods/{$food->id}")->assertOk()->json('data.addon_groups'))
            ->flatMap(fn ($group) => $group['addons'])
            ->keyBy('addon_name')
            ->all();
    }

    // -----------------------------------------------------------------
    // The Store Manager's catalogue
    // -----------------------------------------------------------------

    public function test_the_manager_adds_an_add_on_and_picks_the_dishes_that_offer_it(): void
    {
        $inasal = $this->food('Chicken Inasal');
        $fried = $this->food('Fried Chicken');
        $this->food('Tocino');

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/addons', [
                'addon_name' => 'Fries',
                'addon_group' => 'Sides',
                'addon_price' => 65,
                'food_ids' => [$inasal->id, $fried->id],
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Fries was added.')
            ->assertJsonPath('data.addon_price', 65)
            ->assertJsonPath('data.is_sized', false)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.food_ids', [$inasal->id, $fried->id]);

        $this->assertArrayHasKey('Fries', $this->offered($inasal));
        $this->assertSame(65, (int) $this->offered($fried)['Fries']['addon_price']);
        $this->assertSame([], $this->offered(Food::where('food_name', 'Tocino')->firstOrFail()));

        Event::assertDispatched(AddonBroadcast::class, fn (AddonBroadcast $event) => $event->eventName === 'created'
            && $event->broadcastOn()[0]->name === 'menu');
    }

    public function test_a_sized_add_on_is_priced_per_size_and_offered_from_its_lowest_price(): void
    {
        $inasal = $this->food();

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/addons', [
                'addon_name' => 'Iced Tea',
                'addon_group' => 'Drinks',
                'sizes' => [
                    ['size' => 'large', 'price' => 65],
                    ['size' => 'small', 'price' => 45],
                ],
                'food_ids' => [$inasal->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_sized', true)
            ->assertJsonPath('data.addon_price', 45)
            ->assertJsonPath('data.sizes.0.size', 'small')
            ->assertJsonPath('data.sizes.1.size', 'large')
            ->assertJsonPath('data.sizes.1.label', 'Large')
            ->assertJsonPath('data.sizes.1.is_available', true);

        $tea = $this->offered($inasal)['Iced Tea'];

        $this->assertSame(45, (int) $tea['addon_price']);
        $this->assertSame(['small', 'large'], array_column($tea['sizes'], 'size'));
        $this->assertSame([45, 65], array_map('intval', array_column($tea['sizes'], 'price')));
    }

    public function test_an_add_on_needs_a_name_a_group_and_a_price_or_sizes(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/addons', ['addon_name' => '', 'addon_group' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['addon_name', 'addon_group', 'addon_price']);

        $this->postJson('/api/addons', ['addon_name' => 'Coke', 'addon_group' => 'Drinks', 'addon_price' => 10.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['addon_price']);

        $this->postJson('/api/addons', [
            'addon_name' => 'Coke',
            'addon_group' => 'Drinks',
            'sizes' => [['size' => 'small', 'price' => 30], ['size' => 'small', 'price' => 40]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['sizes.0.size']);

        $this->postJson('/api/addons', [
            'addon_name' => 'Coke',
            'addon_group' => 'Drinks',
            'sizes' => [['size' => 'jumbo', 'price' => 30]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['sizes.0.size']);

        $this->assertSame(0, Addon::count());
    }

    public function test_two_add_ons_cannot_share_a_name(): void
    {
        Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => 65, 'addon_group' => 'Drinks']);

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson('/api/addons', ['addon_name' => ' iced tea ', 'addon_group' => 'Drinks', 'addon_price' => 60])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['addon_name' => 'An add-on named "iced tea" is already in the catalog.']);
    }

    public function test_editing_without_a_price_change_needs_no_password_and_tells_nobody(): void
    {
        $tea = $this->sizedTea(['small' => 45, 'medium' => 55]);

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/addons/{$tea->id}", [
                'addon_name' => 'House Iced Tea',
                'addon_group' => 'Drinks',
                // Adding a size changes no price it already had.
                'sizes' => [
                    ['size' => 'small', 'price' => 45],
                    ['size' => 'medium', 'price' => 55],
                    ['size' => 'large', 'price' => 65],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.addon_name', 'House Iced Tea')
            ->assertJsonCount(3, 'data.sizes');

        Queue::assertNotPushed(NotifyEveryAccount::class);
        Event::assertNotDispatched(PriceBroadcast::class);
    }

    public function test_a_price_change_asks_for_the_password_then_is_announced(): void
    {
        $inasal = $this->food();
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides']);
        $fries->foods()->sync([$inasal->id]);

        $payload = ['addon_name' => 'Fries', 'addon_group' => 'Sides', 'addon_price' => 70];

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/addons/{$fries->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->assertSame('65.00', $fries->fresh()->addon_price);

        $this->putJson("/api/addons/{$fries->id}", $payload + ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.addon_price', 70);

        Queue::assertPushed(NotifyEveryAccount::class, fn (NotifyEveryAccount $job) => $job->body === 'Fries is now ₱70, changed from ₱65.');
        Event::assertDispatched(PriceBroadcast::class, fn (PriceBroadcast $event) => $event->foodIds === [$inasal->id]);
    }

    public function test_a_size_price_change_names_the_size(): void
    {
        $tea = $this->sizedTea();

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/addons/{$tea->id}", [
                'addon_name' => 'Iced Tea',
                'addon_group' => 'Drinks',
                'sizes' => [
                    ['size' => 'small', 'price' => 45],
                    ['size' => 'medium', 'price' => 55],
                    ['size' => 'large', 'price' => 70],
                ],
                'password' => self::PASSWORD,
            ])
            ->assertOk();

        Queue::assertPushed(NotifyEveryAccount::class, fn (NotifyEveryAccount $job) => $job->body === 'Iced Tea (Large) is now ₱70, changed from ₱65.');
    }

    public function test_switching_from_one_price_to_sizes_is_a_price_change(): void
    {
        $tea = Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => 65, 'addon_group' => 'Drinks']);

        $payload = [
            'addon_name' => 'Iced Tea',
            'addon_group' => 'Drinks',
            'sizes' => [['size' => 'small', 'price' => 45], ['size' => 'large', 'price' => 65]],
        ];

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/addons/{$tea->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->putJson("/api/addons/{$tea->id}", $payload + ['password' => self::PASSWORD])->assertOk();

        Queue::assertPushed(NotifyEveryAccount::class, fn (NotifyEveryAccount $job) => $job->body === 'Iced Tea is now Small ₱45 and Large ₱65.');
    }

    public function test_archiving_is_the_same_switch_the_store_agent_uses(): void
    {
        $inasal = $this->food();
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides']);
        $fries->foods()->sync([$inasal->id]);

        $this->actingAs($this->manager(), 'sanctum')
            ->patchJson("/api/addons/{$fries->id}/archive", ['archived' => true])
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('message', 'Fries was archived. Customers no longer see it.');

        $this->assertSame([], $this->offered($inasal));

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson("/api/addons/{$fries->id}/availability", ['is_available' => true])
            ->assertOk()
            ->assertJsonPath('data.is_available', true);

        $this->assertArrayHasKey('Fries', $this->offered($inasal));
    }

    public function test_deleting_keeps_past_orders_whole_and_empties_carts(): void
    {
        $inasal = $this->food();
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides']);
        $fries->foods()->sync([$inasal->id]);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $cart = Cart::create(['user_id' => $customer->id, 'cart_status' => Cart::STATUS_IMMEDIATE]);
        $line = $cart->items()->create(['user_id' => $customer->id, 'food_id' => $inasal->id, 'quantity' => 1]);
        $line->selectedAddons()->sync([$fries->id => ['size' => null]]);

        $order = $this->orderWith(['user_id' => $customer->id, 'status' => OrderStatus::COMPLETED, 'total_amount' => 265, 'total_price' => 265]);
        $item = OrderItem::create(['order_id' => $order->id, 'food_id' => $inasal->id, 'quantity' => 1, 'price' => 265, 'unit_price' => 265, 'subtotal' => 265]);
        $item->addonSelections()->create(['addon_id' => $fries->id, 'unit_price' => 65]);

        $this->actingAs($this->manager(), 'sanctum')
            ->deleteJson("/api/addons/{$fries->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Fries was deleted from the catalog.');

        $this->assertSoftDeleted('addons', ['id' => $fries->id]);
        $this->assertSame(0, $line->selectedAddons()->count(), 'It leaves carts.');
        $this->assertSame([], $this->offered($inasal), 'It leaves the dish.');
        $this->assertSame(['Fries'], $item->fresh()->addons->pluck('addon_name')->all(), 'Past orders keep it.');

        $this->getJson('/api/addons')->assertOk()->assertJsonCount(0, 'data');

        // Its name is free again.
        $this->postJson('/api/addons', ['addon_name' => 'Fries', 'addon_group' => 'Sides', 'addon_price' => 70])->assertCreated();
    }

    public function test_removing_a_size_takes_it_out_of_carts(): void
    {
        $inasal = $this->food();
        $tea = $this->sizedTea();
        $tea->foods()->sync([$inasal->id]);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $cart = Cart::create(['user_id' => $customer->id, 'cart_status' => Cart::STATUS_IMMEDIATE]);
        $large = $cart->items()->create(['user_id' => $customer->id, 'food_id' => $inasal->id, 'quantity' => 1]);
        $large->selectedAddons()->sync([$tea->id => ['size' => 'large']]);
        $small = $cart->items()->create(['user_id' => $customer->id, 'food_id' => $inasal->id, 'quantity' => 1]);
        $small->selectedAddons()->sync([$tea->id => ['size' => 'small']]);

        $this->actingAs($this->manager(), 'sanctum')
            ->putJson("/api/addons/{$tea->id}", [
                'addon_name' => 'Iced Tea',
                'addon_group' => 'Drinks',
                'sizes' => [['size' => 'small', 'price' => 45], ['size' => 'medium', 'price' => 55]],
            ])
            ->assertOk();

        $this->assertSame(0, $large->selectedAddons()->count());
        $this->assertSame(1, $small->selectedAddons()->count());
    }

    public function test_the_product_form_sets_and_clears_a_dishs_add_ons(): void
    {
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides']);
        $tea = Addon::create(['addon_name' => 'Iced Tea', 'addon_price' => 65, 'addon_group' => 'Drinks']);
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->post('/api/foods', [
                'food_name' => 'Chicken Inasal',
                'price' => 200,
                'category_id' => Category::create(['name' => 'Chicken'])->id,
                'thumbnail' => UploadedFile::fake()->image('inasal.png'),
                'addon_ids' => [$fries->id, $tea->id],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.addon_ids', [$fries->id, $tea->id]);

        $food = Food::where('food_name', 'Chicken Inasal')->firstOrFail();

        // A pick of an add-on the dish stops offering leaves carts.
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $cart = Cart::create(['user_id' => $customer->id, 'cart_status' => Cart::STATUS_IMMEDIATE]);
        $line = $cart->items()->create(['user_id' => $customer->id, 'food_id' => $food->id, 'quantity' => 1]);
        $line->selectedAddons()->sync([$fries->id => ['size' => null], $tea->id => ['size' => null]]);

        $base = ['food_name' => 'Chicken Inasal', 'price' => 200, 'category_id' => $food->category_id];

        $this->putJson("/api/foods/{$food->id}", $base + ['addon_ids' => [$tea->id]])
            ->assertOk()
            ->assertJsonPath('data.addon_ids', [$tea->id]);

        $this->assertSame([$tea->id], $line->selectedAddons()->pluck('addons.id')->all());

        // Left out: unchanged. Sent empty: none.
        $this->putJson("/api/foods/{$food->id}", $base)->assertOk()->assertJsonPath('data.addon_ids', [$tea->id]);
        $this->putJson("/api/foods/{$food->id}", $base + ['addon_ids' => null])->assertOk()->assertJsonPath('data.addon_ids', []);
    }

    // -----------------------------------------------------------------
    // The Store Agent's switches
    // -----------------------------------------------------------------

    public function test_the_agent_switches_a_size_off_and_customers_stop_seeing_it(): void
    {
        $inasal = $this->food();
        $tea = $this->sizedTea();
        $tea->foods()->sync([$inasal->id]);

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson("/api/addons/{$tea->id}/sizes/large", ['is_available' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Iced Tea (Large) is switched off.')
            ->assertJsonPath('data.sizes.2.is_available', false);

        $this->assertSame(['small', 'medium'], array_column($this->offered($inasal)['Iced Tea']['sizes'], 'size'));

        $this->patchJson("/api/addons/{$tea->id}/sizes/small", ['is_available' => false])->assertOk();
        $this->patchJson("/api/addons/{$tea->id}/sizes/medium", ['is_available' => false])->assertOk();

        $this->assertSame([], $this->offered($inasal), 'With every size off, it is not offered at all.');

        $this->patchJson("/api/addons/{$tea->id}/sizes/large", ['is_available' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Iced Tea (Large) is available again.');

        $this->assertSame(65, (int) $this->offered($inasal)['Iced Tea']['addon_price']);
    }

    public function test_a_size_the_add_on_does_not_come_in_cannot_be_switched(): void
    {
        $tea = $this->sizedTea(['small' => 45]);

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson("/api/addons/{$tea->id}/sizes/large", ['is_available' => false])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'SIZE_NOT_OFFERED');

        $this->patchJson("/api/addons/{$tea->id}/sizes/jumbo", ['is_available' => false])->assertNotFound();
    }

    public function test_the_agent_switches_the_ticked_add_ons_together(): void
    {
        $inasal = $this->food();
        $tea = $this->sizedTea();
        $fries = Addon::create(['addon_name' => 'Fries', 'addon_price' => 65, 'addon_group' => 'Sides']);
        $rice = Addon::create(['addon_name' => 'Rice', 'addon_price' => 20, 'addon_group' => 'Sides']);
        $inasal->addons()->sync([$tea->id, $fries->id, $rice->id]);

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson('/api/agent/addons/availability', ['addon_ids' => [$tea->id, $fries->id], 'is_available' => false])
            ->assertOk()
            ->assertJsonPath('message', '2 add-ons switched off.')
            ->assertJsonPath('data.0.addon_name', 'Fries')
            ->assertJsonPath('data.0.is_available', false);

        $this->assertSame(['Rice'], array_keys($this->offered($inasal)));
        Event::assertDispatchedTimes(AddonBroadcast::class, 2);

        // Already off: nothing to change or announce again.
        $this->patchJson('/api/agent/addons/availability', ['addon_ids' => [$fries->id], 'is_available' => false])
            ->assertOk()
            ->assertJsonPath('message', '1 add-on switched off.');
        Event::assertDispatchedTimes(AddonBroadcast::class, 2);

        $this->patchJson('/api/agent/addons/availability', ['addon_ids' => [$tea->id, $fries->id], 'is_available' => true])
            ->assertOk()
            ->assertJsonPath('message', '2 add-ons back on the menu.');

        $this->assertSame(['Fries', 'Iced Tea', 'Rice'], collect(array_keys($this->offered($inasal)))->sort()->values()->all());
    }

    public function test_only_real_add_ons_are_switched_and_only_by_the_agent(): void
    {
        $tea = $this->sizedTea();
        $gone = Addon::create(['addon_name' => 'Gone', 'addon_price' => 10, 'addon_group' => 'Sides']);
        $gone->delete();

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson('/api/agent/addons/availability', ['addon_ids' => [], 'is_available' => false])
            ->assertJsonValidationErrors('addon_ids');
        $this->patchJson('/api/agent/addons/availability', ['addon_ids' => [$gone->id], 'is_available' => false])
            ->assertJsonValidationErrors('addon_ids.0');

        $this->actingAs($this->manager(), 'sanctum')
            ->patchJson('/api/agent/addons/availability', ['addon_ids' => [$tea->id], 'is_available' => false])
            ->assertForbidden();

        $this->assertTrue($tea->fresh()->availability);
    }

    public function test_both_staff_roles_read_the_list_with_what_each_may_do(): void
    {
        $this->sizedTea();

        $this->actingAs($this->agent(), 'sanctum')
            ->getJson('/api/addons')
            ->assertOk()
            ->assertJsonPath('data.0.addon_name', 'Iced Tea')
            ->assertJsonPath('meta.can_manage', true)
            ->assertJsonPath('meta.can_edit', false)
            ->assertJsonPath('meta.groups', ['Drinks', 'Sides']);

        $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/addons')
            ->assertOk()
            ->assertJsonPath('meta.can_manage', false)
            ->assertJsonPath('meta.can_edit', true);
    }

    public function test_each_role_is_kept_to_its_own_side(): void
    {
        $tea = $this->sizedTea();
        $agent = $this->agent();
        $payload = ['addon_name' => 'Coke', 'addon_group' => 'Drinks', 'addon_price' => 30];

        // The catalogue is the Store Manager's (BR-29).
        $this->actingAs($agent, 'sanctum')->postJson('/api/addons', $payload)->assertForbidden();
        $this->actingAs($agent, 'sanctum')->putJson("/api/addons/{$tea->id}", $payload)->assertForbidden();
        $this->actingAs($agent, 'sanctum')->patchJson("/api/addons/{$tea->id}/archive", ['archived' => true])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->deleteJson("/api/addons/{$tea->id}")->assertForbidden();

        // The switches are the Store Agent's; the Store Manager reads them (FR-07.9).
        $manager = $this->manager();
        $this->actingAs($manager, 'sanctum')->patchJson("/api/addons/{$tea->id}/availability", ['is_available' => false])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->patchJson("/api/addons/{$tea->id}/sizes/large", ['is_available' => false])->assertForbidden();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($customer, 'sanctum')->getJson('/api/addons')->assertForbidden();

        $this->assertTrue($tea->fresh()->availability);
        $this->assertTrue((bool) $tea->sizes()->where('size', 'large')->value('is_available'));
    }

    public function test_the_list_needs_a_session(): void
    {
        $this->getJson('/api/addons')->assertUnauthorized();
    }
}
