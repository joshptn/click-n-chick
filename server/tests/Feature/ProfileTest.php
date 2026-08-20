<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\User;
use App\Services\Media\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * Account settings: profile, addresses, and statutory discount eligibility
 * (UC-PROF-001 / UC-PROF-002, BR-31 discount claims).
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private int $phoneSeq = 0;

    /**
     * Stand in for Cloudinary.
     *
     * No credentials are configured under test, so the real service refuses
     * and the controller correctly answers 502. Swapping the binding keeps
     * these tests about the claim rules rather than about the uploader, and
     * keeps them offline - a suite that reaches a third party over the network
     * fails for reasons that have nothing to do with the code under test.
     */
    private function fakeUploads(string $url = 'https://res.cloudinary.test/discount-ids/id.jpg'): void
    {
        $this->instance(
            CloudinaryService::class,
            Mockery::mock(CloudinaryService::class, function ($mock) use ($url) {
                $mock->shouldReceive('upload')->andReturn($url);
                $mock->shouldReceive('delete')->andReturnNull();
                $mock->shouldReceive('isConfigured')->andReturnTrue();
            })
        );
    }

    private function customer(array $overrides = []): User
    {
        $phone = '+6391714'.str_pad((string) (++$this->phoneSeq), 5, '0', STR_PAD_LEFT);

        $user = User::factory()->create(array_merge([
            'password' => Hash::make(self::PASSWORD),
            'role' => User::ROLE_CUSTOMER,
            'phone_number' => $phone,
            'phone_number_hash' => User::hashPhoneNumber($phone),
            // Email-verified by default so the phone is not load-bearing and
            // can be edited; the SMS cases set this up explicitly.
            'verification_channel' => 'email',
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ], $overrides));

        return $user->fresh();
    }

    private function staff(string $role = User::ROLE_ADMIN): User
    {
        return $this->customer(['role' => $role]);
    }

    // -----------------------------------------------------------------
    // Reading the page
    // -----------------------------------------------------------------

    public function test_the_profile_returns_the_whole_page_in_one_response(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'profile', 'addresses', 'discount', 'loyalty_points']);
    }

    public function test_a_guest_cannot_read_a_profile(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    /** Staff get no discount section at all - it is absent, not merely hidden. */
    public function test_staff_do_not_get_a_discount_section(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $this->app['auth']->forgetGuards();

            $this->actingAs($this->staff($role), 'sanctum')
                ->getJson('/api/profile')
                ->assertOk()
                ->assertJsonPath('discount', null);
        }
    }

    public function test_a_customer_does_get_a_discount_section(): void
    {
        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('discount.can_apply', true)
            ->assertJsonPath('discount.is_eligible', false);
    }

    // -----------------------------------------------------------------
    // Editing
    // -----------------------------------------------------------------

    public function test_names_and_consent_save(): void
    {
        $user = $this->customer(['privacy_consent_at' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'first_name' => 'Julia',
                'last_name' => 'Veneese',
                'privacy_consent' => true,
            ])
            ->assertOk()
            ->assertJsonPath('user.first_name', 'Julia');

        $fresh = $user->fresh();

        $this->assertSame('Veneese', $fresh->last_name);
        $this->assertNotNull($fresh->privacy_consent_at);
    }

    public function test_withdrawing_consent_clears_the_timestamp(): void
    {
        $user = $this->customer(['privacy_consent_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['privacy_consent' => false])
            ->assertOk();

        $this->assertNull($user->fresh()->privacy_consent_at);
    }

    public function test_the_profile_endpoint_cannot_change_the_role(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'first_name' => 'Julia',
                'role' => User::ROLE_SUPER_ADMIN,
            ])
            ->assertOk();

        $this->assertSame(User::ROLE_CUSTOMER, $user->fresh()->role, 'Privilege escalation through the profile form.');
    }

    /**
     * Changing the number must not leave the account "verified" for a number
     * nobody ever proved they hold.
     */
    public function test_changing_the_phone_number_clears_its_verification(): void
    {
        $user = $this->customer(['phone_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['phone_number' => '09991234567'])
            ->assertOk();

        $fresh = $user->fresh();

        $this->assertSame(User::hashPhoneNumber('09991234567'), $fresh->phone_number_hash);
        $this->assertNull($fresh->phone_verified_at);
    }

    public function test_the_phone_cannot_be_changed_while_it_gates_sign_in(): void
    {
        $user = $this->customer([
            'verification_channel' => 'sms',
            'phone_verified_at' => now(),
            'email_verified_at' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['phone_number' => '09991234568'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');

        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_the_phone_cannot_be_changed_while_two_factor_sends_codes_to_it(): void
    {
        $user = $this->customer([
            'two_factor_enabled' => true,
            'two_factor_channel' => 'sms',
            'phone_verified_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['phone_number' => '09991234569'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');
    }

    public function test_a_phone_number_already_on_another_account_is_refused(): void
    {
        $taken = $this->customer();
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['phone_number' => $taken->phone_number])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');
    }

    // -----------------------------------------------------------------
    // Addresses
    // -----------------------------------------------------------------

    public function test_the_first_address_becomes_the_default(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/addresses', ['label' => 'home', 'full_address' => 'Blk 1 Lot 5, Larlin Village'])
            ->assertCreated()
            ->assertJsonPath('address.is_default', true);
    }

    public function test_only_one_address_is_ever_the_default(): void
    {
        $user = $this->customer();

        foreach (['home', 'work', 'other'] as $label) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/addresses', [
                    'label' => $label,
                    'full_address' => "{$label} address",
                    'is_default' => true,
                ])
                ->assertCreated();
        }

        $this->assertSame(1, $user->addresses()->where('is_default', true)->count());
    }

    public function test_deleting_the_default_promotes_another_address(): void
    {
        $user = $this->customer();

        $first = $user->addresses()->create(['label' => 'home', 'full_address' => 'A', 'is_default' => true]);
        $user->addresses()->create(['label' => 'work', 'full_address' => 'B', 'is_default' => false]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/addresses/{$first->id}")
            ->assertOk();

        $this->assertSame(1, $user->addresses()->where('is_default', true)->count(), 'Addresses left with no default.');
    }

    public function test_another_accounts_address_is_not_reachable(): void
    {
        $owner = $this->customer();
        $stranger = $this->customer();

        $address = $owner->addresses()->create(['label' => 'home', 'full_address' => 'Private']);

        // 404, not 403: a 403 would confirm the id exists.
        $this->actingAs($stranger, 'sanctum')
            ->putJson("/api/addresses/{$address->id}", ['label' => 'home', 'full_address' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Private', $address->fresh()->full_address);

        $this->app['auth']->forgetGuards();
        $this->actingAs($stranger, 'sanctum')
            ->deleteJson("/api/addresses/{$address->id}")
            ->assertStatus(404);

        $this->assertNotNull($address->fresh());
    }

    // -----------------------------------------------------------------
    // Discount eligibility
    // -----------------------------------------------------------------

    /**
     * A stand-in ID photo.
     *
     * create() with an explicit MIME rather than image(), which needs the GD
     * extension to synthesise pixels. GD is not enabled in this PHP build and
     * the application never needs it - uploads are posted straight to
     * Cloudinary - so requiring it just to run the suite would be a test-only
     * dependency on something production does not use.
     */
    private function idImage(): UploadedFile
    {
        $this->fakeUploads();

        return UploadedFile::fake()->create('senior-id.jpg', 120, 'image/jpeg');
    }

    public function test_a_customer_can_apply_and_the_claim_starts_pending(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->post('/api/discount', [
                'discount_type' => Discount::TYPE_SENIOR,
                'id_image' => $this->idImage(),
            ])
            ->assertCreated()
            ->assertJsonPath('claim.discount_status', Discount::STATUS_PENDING)
            ->assertJsonPath('is_eligible', false);

        $this->assertDatabaseHas('discounts', [
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'discount_status' => Discount::STATUS_PENDING,
        ]);
    }

    /** The hand-off: agents must find out there is something to review. */
    public function test_applying_notifies_the_store_agents(): void
    {
        $agent = $this->staff(User::ROLE_ADMIN);
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->post('/api/discount', ['discount_type' => Discount::TYPE_PWD, 'id_image' => $this->idImage()])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'title' => 'Discount application to review',
        ]);
    }

    public function test_a_second_application_is_refused_while_one_is_pending(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->post('/api/discount', ['discount_type' => Discount::TYPE_SENIOR, 'id_image' => $this->idImage()])
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'sanctum')
            ->post('/api/discount', ['discount_type' => Discount::TYPE_SENIOR, 'id_image' => $this->idImage()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALREADY_PENDING');

        $this->assertSame(1, Discount::where('user_id', $user->id)->count());
    }

    public function test_an_approved_customer_cannot_apply_again(): void
    {
        $user = $this->customer();
        Discount::create([
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_APPROVED,
        ]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/discount', ['discount_type' => Discount::TYPE_SENIOR, 'id_image' => $this->idImage()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALREADY_ELIGIBLE');
    }

    /** A blurry photo must not be a permanent bar. */
    public function test_a_rejected_customer_may_apply_again(): void
    {
        $user = $this->customer();
        Discount::create([
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_REJECTED,
            'rejection_reason' => 'The photo was too blurry to read.',
        ]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/discount', ['discount_type' => Discount::TYPE_SENIOR, 'id_image' => $this->idImage()])
            ->assertCreated()
            ->assertJsonPath('claim.discount_status', Discount::STATUS_PENDING);
    }

    /**
     * A failed upload must not consume the customer's one live claim.
     *
     * Image::uploadImage returns null rather than throwing, so without the
     * explicit check the row would be written with an empty id_image - a claim
     * an agent can only reject, which then blocks the customer from applying
     * again until someone clears it by hand.
     */
    public function test_a_failed_upload_creates_no_claim(): void
    {
        $user = $this->customer();

        $this->instance(
            CloudinaryService::class,
            Mockery::mock(CloudinaryService::class, function ($mock) {
                $mock->shouldReceive('upload')->andThrow(new \RuntimeException('Cloudinary is down.'));
            })
        );

        $this->actingAs($user, 'sanctum')
            ->post('/api/discount', [
                'discount_type' => Discount::TYPE_SENIOR,
                'id_image' => UploadedFile::fake()->create('senior-id.jpg', 120, 'image/jpeg'),
            ])
            ->assertStatus(502);

        $this->assertSame(0, Discount::where('user_id', $user->id)->count());
    }

    public function test_staff_cannot_apply_for_a_customer_discount(): void
    {
        $this->actingAs($this->staff(), 'sanctum')
            ->post('/api/discount', ['discount_type' => Discount::TYPE_SENIOR, 'id_image' => $this->idImage()])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Store Agent review
    // -----------------------------------------------------------------

    private function pendingClaim(User $user): Discount
    {
        return Discount::create([
            'user_id' => $user->id,
            'discount_type' => Discount::TYPE_SENIOR,
            'id_image' => 'https://example.test/id.jpg',
            'discount_status' => Discount::STATUS_PENDING,
        ]);
    }

    public function test_an_agent_approves_a_claim_and_the_customer_becomes_eligible(): void
    {
        $customer = $this->customer();
        $agent = $this->staff(User::ROLE_ADMIN);
        $claim = $this->pendingClaim($customer);

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertOk()
            ->assertJsonPath('claim.discount_status', Discount::STATUS_APPROVED);

        $this->assertTrue($customer->fresh()->isDiscountEligible());
        $this->assertSame($agent->id, $claim->fresh()->verified_by);
        $this->assertNotNull($claim->fresh()->verified_at);
    }

    public function test_rejecting_requires_a_reason_the_customer_can_act_on(): void
    {
        $customer = $this->customer();
        $claim = $this->pendingClaim($customer);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors('rejection_reason');

        $this->assertTrue($claim->fresh()->isPending());
    }

    public function test_a_rejection_records_the_reason_and_leaves_the_customer_ineligible(): void
    {
        $customer = $this->customer();
        $claim = $this->pendingClaim($customer);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/reject", [
                'rejection_reason' => 'The photo was too blurry to read.',
            ])
            ->assertOk();

        $this->assertSame('The photo was too blurry to read.', $claim->fresh()->rejection_reason);
        $this->assertFalse($customer->fresh()->isDiscountEligible());
    }

    public function test_both_decisions_notify_the_customer(): void
    {
        $customer = $this->customer();
        $claim = $this->pendingClaim($customer);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'title' => 'Discount approved',
        ]);
    }

    /** Two agents opening the same queue must not both settle one claim. */
    public function test_a_claim_cannot_be_reviewed_twice(): void
    {
        $customer = $this->customer();
        $claim = $this->pendingClaim($customer);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/reject", ['rejection_reason' => 'Changed my mind.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_REVIEWED');

        $this->assertTrue($claim->fresh()->isApproved(), 'An approval was silently overwritten.');
    }

    public function test_a_customer_cannot_reach_the_review_queue_or_approve_anything(): void
    {
        $customer = $this->customer();
        $claim = $this->pendingClaim($customer);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/admin/discount-claims')
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();

        // Their OWN claim, which is the tempting one to let through.
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/admin/discount-claims/{$claim->id}/approve")
            ->assertStatus(403);

        $this->assertTrue($claim->fresh()->isPending());
    }

    public function test_the_review_queue_lists_pending_claims_oldest_first(): void
    {
        $older = $this->pendingClaim($this->customer());
        $this->travel(5)->minutes();
        $newer = $this->pendingClaim($this->customer());

        $response = $this->actingAs($this->staff(), 'sanctum')
            ->getJson('/api/admin/discount-claims')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame([$older->id, $newer->id], $ids, 'A newest-first queue starves the longest waiter.');
    }
}
