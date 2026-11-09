<?php

namespace Tests\Feature;

use App\Events\DiscountClaimBroadcast;
use App\Events\OrderBroadcast;
use App\Http\Controllers\DiscountController;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Media\CloudinaryService;
use App\Services\Orders\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Store Manager monitors All Orders, Advance Orders and Discount Requests
 * but does not run them. Each refused write is checked against the database and
 * the broadcast log, not just the status code.
 */
class StoreManagerReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = User::ROLE_CUSTOMER): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function order(string $status = OrderStatus::PLACED, ?User $owner = null, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => ($owner ?? $this->user())->id,
            'total_price' => 200,
            'total_amount' => 200,
            'status' => $status,
            'order_type' => 'pickup',
        ], $overrides));
    }

    private function advanceOrder(string $status = OrderStatus::SUBMITTED): Order
    {
        return $this->order($status, null, ['scheduled_for' => now()->addDays(2)]);
    }

    private function claim(): Discount
    {
        return Discount::create([
            'user_id' => $this->user()->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://res.cloudinary.test/discount-ids/id.jpg',
            'discount_status' => Discount::STATUS_PENDING,
        ]);
    }

    /** Every Store Agent write on the three pages: method, path, body, needs-an-advance-order. */
    public static function agentWrites(): array
    {
        return [
            'order status' => ['putJson', '/api/order/%d/status', ['status' => OrderStatus::CONFIRMED], false],
            'order estimate' => ['putJson', '/api/order/%d/etc', ['etc' => 20], false],
            'advance an order' => ['postJson', '/api/agent/orders/%d/advance', [], false],
            'cancel an order' => ['postJson', '/api/agent/orders/%d/cancel', ['reason' => 'Out of stock.'], false],
            'accept a request' => ['postJson', '/api/agent/advance-requests/%d/accept', [], true],
            'decline a request' => ['postJson', '/api/agent/advance-requests/%d/reject', ['reason' => 'Fully booked.'], true],
        ];
    }

    #[DataProvider('agentWrites')]
    public function test_the_store_manager_cannot_perform(string $method, string $path, array $body, bool $advance): void
    {
        Event::fake([OrderBroadcast::class]);

        $order = $advance ? $this->advanceOrder() : $this->order();
        $before = $order->fresh()->status;

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->{$method}(sprintf($path, $order->id), $body)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ROLE_FORBIDDEN');

        $this->assertSame($before, $order->fresh()->status);
        Event::assertNotDispatched(OrderBroadcast::class);
    }

    #[DataProvider('agentWrites')]
    public function test_the_store_agent_still_can_perform(string $method, string $path, array $body, bool $advance): void
    {
        Event::fake([OrderBroadcast::class]);

        $order = $advance ? $this->advanceOrder() : $this->order();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->{$method}(sprintf($path, $order->id), $body)
            ->assertOk();
    }

    public function test_the_store_manager_cannot_remind_a_customer_to_pay(): void
    {
        $order = $this->advanceOrder(OrderStatus::AWAITING_PAYMENT);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN), 'sanctum')
            ->postJson("/api/agent/advance-requests/{$order->id}/remind")
            ->assertForbidden();

        $this->assertSame(0, Notification::query()->where('order_id', $order->id)->count());
    }

    public function test_the_store_manager_cannot_decide_a_discount_claim(): void
    {
        Event::fake([DiscountClaimBroadcast::class]);

        $claim = $this->claim();
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertForbidden();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/reject", ['rejection_reason' => 'Blurry.'])
            ->assertForbidden();

        $fresh = $claim->fresh();

        $this->assertSame(Discount::STATUS_PENDING, $fresh->discount_status);
        $this->assertNull($fresh->verified_by);
        Event::assertNotDispatched(DiscountClaimBroadcast::class);

        $this->assertFalse(Gate::forUser($manager)->allows('approve', $claim));
        $this->assertFalse(Gate::forUser($manager)->allows('reject', $claim));
    }

    public function test_the_store_agent_can_still_decide_a_discount_claim(): void
    {
        $claim = $this->claim();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertOk();

        $this->assertSame(Discount::STATUS_APPROVED, $claim->fresh()->discount_status);
    }

    public function test_the_store_manager_can_read_all_three_pages_and_the_records(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        $request = $this->advanceOrder();
        $this->claim();

        foreach ([
            '/api/agent/queue',
            '/api/agent/queue/next',
            '/api/agent/advance-requests',
            "/api/agent/advance-requests/{$request->id}",
            '/api/admin/discount-claims',
            '/api/admin/discount-claims?status=approved',
            '/api/admin/discount-claims?status=rejected',
            '/api/agent/records',
            '/api/orders/all',
        ] as $path) {
            $this->actingAs($manager, 'sanctum')->getJson($path)->assertOk();
        }
    }

    /** Owning the order must not open the agent's cancel route, which refunds in full. */
    public function test_a_store_manager_who_owns_an_order_cannot_use_the_agents_cancel(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        $order = $this->order(OrderStatus::PLACED, $manager);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/agent/orders/{$order->id}/cancel")
            ->assertForbidden();

        $this->assertSame(OrderStatus::PLACED, $order->fresh()->status);
    }

    public function test_correcting_an_order_is_the_agents_and_the_owners_only(): void
    {
        $manager = $this->user(User::ROLE_SUPER_ADMIN);
        $someoneElses = $this->order();
        $ownOrder = $this->order(OrderStatus::PLACED, $manager);

        $this->assertFalse(Gate::forUser($manager)->allows('amend', $someoneElses));
        $this->assertFalse(Gate::forUser($manager)->allows('cancel', $someoneElses));
        $this->assertFalse(Gate::forUser($manager)->allows('update', $someoneElses));

        // Their own order, placed as a customer, is unaffected.
        $this->assertTrue(Gate::forUser($manager)->allows('amend', $ownOrder));

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/orders/{$someoneElses->id}", ['delivery_note' => 'Leave it outside.'])
            ->assertForbidden();

        $this->assertTrue(Gate::forUser($this->user(User::ROLE_ADMIN))->allows('amend', $someoneElses));
    }

    /** A new write added to these areas has to be placed deliberately. */
    public function test_every_write_under_these_pages_is_agent_only(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! preg_match('#^api/(agent/|admin/discount-claims|order/\{id\}/(status|etc)$)#', $route->uri())) {
                continue;
            }

            if (array_diff($route->methods(), ['GET', 'HEAD']) === []) {
                continue;
            }

            $checked++;

            $this->assertContains('role:admin', $route->gatherMiddleware(), $route->uri().' is open to more than the Store Agent.');
        }

        $this->assertGreaterThanOrEqual(9, $checked);
    }

    public function test_only_the_store_agent_is_asked_to_review_a_new_application(): void
    {
        Event::fake([DiscountClaimBroadcast::class]);

        $agent = $this->user(User::ROLE_ADMIN);
        $manager = $this->user(User::ROLE_SUPER_ADMIN);

        $cloudinary = Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('upload')->andReturn('https://res.cloudinary.test/discount-ids/id.jpg');
        $this->app->instance(CloudinaryService::class, $cloudinary);

        $this->actingAs($this->user(), 'sanctum')
            ->post('/api/discount', [
                'discount_type' => Discount::TYPE_SENIOR,
                'id_image' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', ['user_id' => $agent->id, 'notification_type' => DiscountController::NOTICE_REVIEW]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $manager->id, 'notification_type' => DiscountController::NOTICE_REVIEW]);

        // The manager's queue still hears about it: admin.discounts admits both roles.
        Event::assertDispatched(DiscountClaimBroadcast::class);
    }
}
