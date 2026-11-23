<?php

namespace Tests\Feature;

use App\Events\DiscountClaimBroadcast;
use App\Jobs\SendPushNotification;
use App\Models\Discount;
use App\Models\PushToken;
use App\Models\User;
use App\Services\Auth\PasswordConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Undoing a discount decision (decided 2026-11-23): the Store Agent's safety
 * net for an approval or rejection made without a proper look at the ID. The
 * claim goes back to Pending, the password is asked every time, and the
 * customer is told.
 */
class DiscountUndoTest extends TestCase
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

    private function claim(string $status, array $overrides = []): Discount
    {
        return Discount::create(array_merge([
            'user_id' => $this->user()->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://res.cloudinary.test/discount-ids/id.jpg',
            'discount_status' => $status,
            'verified_at' => $status === Discount::STATUS_PENDING ? null : now()->subDays(3),
        ], $overrides));
    }

    private function undo(User $agent, Discount $claim, ?string $password = 'password')
    {
        return $this->actingAs($agent, 'sanctum')->postJson(
            "/api/admin/discount-claims/{$claim->id}/undo",
            $password === null ? [] : ['password' => $password]
        );
    }

    public function test_undoing_an_approval_puts_the_claim_back_in_pending(): void
    {
        Event::fake([DiscountClaimBroadcast::class]);
        $agent = $this->user(User::ROLE_ADMIN);
        $claim = $this->claim(Discount::STATUS_APPROVED, ['verified_by' => $agent->id]);

        $this->undo($agent, $claim)
            ->assertOk()
            ->assertJsonPath('claim.discount_status', Discount::STATUS_PENDING)
            ->assertJsonPath('claim.is_latest', true);

        $claim->refresh();
        $this->assertTrue($claim->isPending());
        $this->assertNull($claim->verified_by);
        $this->assertNull($claim->verified_at);

        Event::assertDispatched(
            DiscountClaimBroadcast::class,
            fn (DiscountClaimBroadcast $event) => $event->eventName === DiscountClaimBroadcast::REOPENED && $event->claim->is($claim)
        );

        // Pending is not usable at checkout.
        $this->actingAs($claim->user, 'sanctum')
            ->getJson('/api/discount')
            ->assertJsonPath('is_eligible', false);
    }

    public function test_undoing_a_rejection_clears_the_reason(): void
    {
        $claim = $this->claim(Discount::STATUS_REJECTED, ['rejection_reason' => 'Blurry photo.']);

        $this->undo($this->user(User::ROLE_ADMIN), $claim)->assertOk();

        $claim->refresh();
        $this->assertTrue($claim->isPending());
        $this->assertNull($claim->rejection_reason);
    }

    public function test_the_customer_is_told_in_app_and_on_their_phone(): void
    {
        Queue::fake();
        $claim = $this->claim(Discount::STATUS_APPROVED);
        $token = $claim->user->createToken('app');
        PushToken::create([
            'user_id' => $claim->user_id,
            'personal_access_token_id' => $token->accessToken->getKey(),
            'token' => 'fcm-customer',
            'platform' => PushToken::PLATFORM_ANDROID,
        ]);

        $this->undo($this->user(User::ROLE_ADMIN), $claim)->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $claim->user_id,
            'title' => 'Discount under review again',
        ]);
        Queue::assertPushed(SendPushNotification::class);
    }

    public function test_the_password_is_asked_every_time(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $claim = $this->claim(Discount::STATUS_APPROVED);

        // A confirmation moments ago does not carry over to an undo.
        $this->undo($agent, $claim)->assertOk();
        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertOk();

        $this->undo($agent, $claim, null)
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_REQUIRED);

        $this->undo($agent, $claim, 'not-the-password')
            ->assertStatus(422)
            ->assertJsonPath('error_code', PasswordConfirmation::CODE_INVALID);

        $this->assertTrue($claim->fresh()->isApproved());
    }

    public function test_a_claim_already_pending_cannot_be_undone(): void
    {
        $claim = $this->claim(Discount::STATUS_PENDING);

        $this->undo($this->user(User::ROLE_ADMIN), $claim)
            ->assertStatus(409)
            ->assertJsonPath('code', 'NOT_DECIDED');
    }

    public function test_a_rejection_the_customer_has_since_reapplied_after_cannot_be_reopened(): void
    {
        $old = $this->claim(Discount::STATUS_REJECTED, ['rejection_reason' => 'Blurry photo.']);
        $this->claim(Discount::STATUS_PENDING, ['user_id' => $old->user_id]);

        $this->undo($this->user(User::ROLE_ADMIN), $old)
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPLACED');

        $this->assertTrue($old->fresh()->isRejected());
    }

    public function test_the_queue_says_which_claims_can_be_reopened(): void
    {
        $old = $this->claim(Discount::STATUS_REJECTED, ['rejection_reason' => 'Blurry photo.']);
        $newer = $this->claim(Discount::STATUS_REJECTED, ['user_id' => $old->user_id, 'rejection_reason' => 'Expired.']);

        $rows = collect($this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->getJson('/api/admin/discount-claims?status=rejected')
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertFalse($rows[$old->id]['is_latest']);
        $this->assertTrue($rows[$newer->id]['is_latest']);
    }

    public function test_the_store_manager_cannot_undo(): void
    {
        $claim = $this->claim(Discount::STATUS_APPROVED);

        $this->undo($this->user(User::ROLE_SUPER_ADMIN), $claim)->assertForbidden();
        $this->undo($this->user(), $claim)->assertForbidden();

        $this->assertTrue($claim->fresh()->isApproved());
    }

    public function test_a_decision_reports_that_it_can_be_undone(): void
    {
        $claim = $this->claim(Discount::STATUS_PENDING);

        $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertOk()
            ->assertJsonPath('claim.is_latest', true);
    }
}
