<?php

namespace Tests\Feature;

use App\Events\DiscountClaimBroadcast;
use App\Http\Controllers\DiscountController;
use App\Models\Discount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\CloudinaryService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * What the Pending Verification page reads and listens for.
 */
class DiscountReviewQueueTest extends TestCase
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

    private function claim(string $status = Discount::STATUS_PENDING, array $overrides = []): Discount
    {
        return Discount::create(array_merge([
            'user_id' => $this->user()->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://res.cloudinary.test/discount-ids/id.jpg',
            'discount_status' => $status,
        ], $overrides));
    }

    public function test_the_queue_reports_every_status_count_and_the_live_rate(): void
    {
        $this->claim();
        $this->claim();
        $this->claim(Discount::STATUS_APPROVED, ['verified_at' => now()]);
        Setting::put(Setting::DISCOUNT_PERCENTAGE, 25);

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->getJson('/api/admin/discount-claims')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('counts.pending', 2)
            ->assertJsonPath('counts.approved', 1)
            ->assertJsonPath('counts.rejected', 0)
            ->assertJsonPath('percentage', 25);
    }

    public function test_decided_claims_list_the_latest_decision_first(): void
    {
        $earlier = $this->claim(Discount::STATUS_REJECTED, ['verified_at' => now()->subHour(), 'rejection_reason' => 'Blurry.']);
        $later = $this->claim(Discount::STATUS_REJECTED, ['verified_at' => now(), 'rejection_reason' => 'Expired.']);

        $response = $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->getJson('/api/admin/discount-claims?status=rejected')
            ->assertOk();

        $this->assertSame([$later->id, $earlier->id], array_column($response->json('data'), 'id'));
    }

    public function test_a_new_application_reaches_the_staff_queue_live(): void
    {
        Event::fake([DiscountClaimBroadcast::class]);
        $this->instance(CloudinaryService::class, Mockery::mock(CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('upload')->andReturn('https://res.cloudinary.test/discount-ids/id.jpg');
        }));
        $agent = $this->user(User::ROLE_ADMIN);

        $this->actingAs($this->user(), 'sanctum')
            ->post('/api/discount', [
                'discount_type' => Discount::TYPE_PWD,
                'id_image' => UploadedFile::fake()->create('pwd-id.jpg', 120, 'image/jpeg'),
            ])
            ->assertCreated();

        Event::assertDispatched(
            DiscountClaimBroadcast::class,
            fn (DiscountClaimBroadcast $event) => $event->eventName === DiscountClaimBroadcast::SUBMITTED
        );

        // The agent's bell opens the queue from this type.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'notification_type' => DiscountController::NOTICE_REVIEW,
        ]);
    }

    public function test_a_decision_leaves_every_open_queue_live(): void
    {
        Event::fake([DiscountClaimBroadcast::class]);
        $claim = $this->claim();

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertOk();

        Event::assertDispatched(
            DiscountClaimBroadcast::class,
            fn (DiscountClaimBroadcast $event) => $event->eventName === DiscountClaimBroadcast::DECIDED
                && $event->claim->is($claim)
                && $event->claim->discount_status === Discount::STATUS_APPROVED
        );
    }

    public function test_a_refused_decision_announces_nothing(): void
    {
        Event::fake([DiscountClaimBroadcast::class]);
        $claim = $this->claim(Discount::STATUS_APPROVED, ['verified_at' => now()]);

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/reject", ['rejection_reason' => 'Too late.'])
            ->assertStatus(409);

        Event::assertNotDispatched(DiscountClaimBroadcast::class);
    }

    public function test_the_broadcast_is_staff_only_and_names_the_claim_alone(): void
    {
        $event = new DiscountClaimBroadcast($this->claim(), DiscountClaimBroadcast::SUBMITTED);
        $channel = $event->broadcastOn()[0];

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-admin.discounts', $channel->name);
        $this->assertSame('claim', $event->broadcastAs());

        // No photo, no name, no phone: listeners refetch through the policy-gated endpoint.
        $this->assertSame(['event', 'claim'], array_keys($event->broadcastWith()));
        $this->assertSame(['id', 'status'], array_keys($event->broadcastWith()['claim']));
    }
}
