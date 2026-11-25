<?php

namespace Tests\Feature;

use App\Console\Commands\WarnExpiringPosters;
use App\Events\NotificationBroadcast;
use App\Models\Notification;
use App\Models\Poster;
use App\Models\User;
use App\Services\Media\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Homepage posters (decided 2026-11-26): the Store Manager manages them in
 * Store Settings, images arrive cropped to 3:1, expiry is optional and never in
 * the past, and the only notice is the staff warning the day before expiry.
 */
class PosterManagementTest extends TestCase
{
    use RefreshDatabase;

    private const UPLOADED = 'https://res.cloudinary.com/demo/image/upload/posters/new.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([NotificationBroadcast::class]);

        // 10:00 in Manila, after the default 07:00 opening.
        $this->travelTo('2026-12-01 02:00:00');

        $this->mock(CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('upload')->andReturn(self::UPLOADED)->byDefault();
            $mock->shouldReceive('delete')->andReturnNull()->byDefault();
        });
    }

    private function user(string $role, string $status = User::STATUS_ACTIVE): User
    {
        return User::factory()->create(['role' => $role, 'account_status' => $status])->fresh();
    }

    private function manager(): User
    {
        return $this->user(User::ROLE_SUPER_ADMIN);
    }

    private function image(int $width = 1200, int $height = 400): UploadedFile
    {
        return UploadedFile::fake()->image('poster.jpg', $width, $height);
    }

    private function poster(array $attributes = []): Poster
    {
        return Poster::create($attributes + ['image' => 'https://res.cloudinary.com/demo/image/upload/posters/old.jpg']);
    }

    private function upload(array $payload, ?Poster $poster = null)
    {
        return $this->actingAs($this->manager(), 'sanctum')->post(
            $poster ? "/api/admin/posters/{$poster->id}" : '/api/admin/posters',
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    // -----------------------------------------------------------------
    // The homepage
    // -----------------------------------------------------------------

    public function test_the_homepage_shows_posters_on_display_and_unexpired_oldest_first(): void
    {
        $first = $this->poster();
        $second = $this->poster();
        $this->poster(['is_active' => false]);
        $this->poster(['expires_at' => '2026-11-30']);
        $lastDay = $this->poster(['expires_at' => '2026-12-01']);

        $response = $this->getJson('/api/posters')->assertOk();

        $this->assertSame([$first->id, $second->id, $lastDay->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(['id', 'image'], array_keys($response->json('data.0')));
    }

    public function test_expiry_follows_the_store_date_not_utc(): void
    {
        // 02:00 on Dec 2 in Manila, still Dec 1 in UTC.
        $this->travelTo('2026-12-01 18:00:00');
        $this->poster(['expires_at' => '2026-12-01']);

        $this->getJson('/api/posters')->assertOk()->assertJsonCount(0, 'data');
    }

    // -----------------------------------------------------------------
    // Who manages them
    // -----------------------------------------------------------------

    public function test_only_the_store_manager_manages_posters(): void
    {
        $poster = $this->poster();

        foreach ([User::ROLE_CUSTOMER, User::ROLE_ADMIN] as $role) {
            $user = $this->user($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/admin/posters')->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->post('/api/admin/posters', ['image' => $this->image()], ['Accept' => 'application/json'])
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')->deleteJson("/api/admin/posters/{$poster->id}")->assertForbidden();
        }

        $this->assertSame(1, Poster::count());
    }

    public function test_the_manager_list_includes_every_poster_with_its_state(): void
    {
        $this->poster(['is_active' => false]);
        $this->poster(['expires_at' => '2026-11-30']);

        $this->actingAs($this->manager(), 'sanctum')->getJson('/api/admin/posters')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.0.is_expired', false)
            ->assertJsonPath('data.1.expires_at', '2026-11-30')
            ->assertJsonPath('data.1.is_expired', true);
    }

    // -----------------------------------------------------------------
    // Adding
    // -----------------------------------------------------------------

    public function test_the_manager_adds_a_poster_that_never_expires(): void
    {
        $this->upload(['image' => $this->image()])
            ->assertCreated()
            ->assertJsonPath('data.image', self::UPLOADED)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.expires_at', null);
    }

    public function test_the_expiry_can_be_today_or_later_but_never_past(): void
    {
        $this->upload(['image' => $this->image(), 'expires_at' => '2026-12-01'])->assertCreated();
        $this->upload(['image' => $this->image(), 'expires_at' => '2027-01-15'])->assertCreated();

        $this->upload(['image' => $this->image(), 'expires_at' => '2026-11-30'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at' => 'The expiry date cannot be in the past.']);

        $this->assertSame(2, Poster::count());
    }

    public function test_the_image_must_be_cropped_to_3_to_1_and_at_least_the_banner_size(): void
    {
        foreach ([[1199, 400], [1500, 400], [1200, 600]] as [$width, $height]) {
            $this->upload(['image' => $this->image($width, $height)])
                ->assertStatus(422)
                ->assertJsonValidationErrors('image');
        }

        $this->upload(['image' => $this->image(2400, 800)])->assertCreated();
    }

    public function test_the_image_is_at_most_2_mb_and_a_jpg_png_or_webp(): void
    {
        $this->upload(['image' => $this->image()->size(2049)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image' => 'The poster image must be 2 MB or smaller.']);

        $this->upload(['image' => UploadedFile::fake()->image('poster.gif', 1200, 400)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->upload(['image' => $this->image()->size(2048)])->assertCreated();
    }

    // -----------------------------------------------------------------
    // Editing and deleting
    // -----------------------------------------------------------------

    public function test_editing_cannot_set_a_past_date_and_can_clear_it(): void
    {
        $poster = $this->poster(['expires_at' => '2026-12-20']);

        $this->upload(['expires_at' => '2026-11-30'], $poster)->assertStatus(422)->assertJsonValidationErrors('expires_at');
        $this->assertSame('2026-12-20', $poster->fresh()->expires_at->toDateString());

        $this->upload(['expires_at' => ''], $poster)->assertOk()->assertJsonPath('data.expires_at', null);
    }

    public function test_an_expired_poster_can_be_switched_and_given_a_new_date(): void
    {
        $poster = $this->poster(['expires_at' => '2026-11-30']);

        $this->upload(['is_active' => '0'], $poster)->assertOk()->assertJsonPath('data.is_active', false);
        $this->upload(['expires_at' => '2026-12-31'], $poster)
            ->assertOk()
            ->assertJsonPath('data.expires_at', '2026-12-31')
            ->assertJsonPath('data.is_expired', false);
    }

    public function test_replacing_the_image_removes_the_old_one(): void
    {
        $poster = $this->poster();

        $this->mock(CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('upload')->once()->andReturn(self::UPLOADED);
            $mock->shouldReceive('delete')->once()->with('https://res.cloudinary.com/demo/image/upload/posters/old.jpg', 'posters');
        });

        $this->upload(['image' => $this->image()], $poster)->assertOk()->assertJsonPath('data.image', self::UPLOADED);
    }

    public function test_deleting_removes_the_row_and_its_image(): void
    {
        $poster = $this->poster();

        $this->mock(CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('delete')->once()->with('https://res.cloudinary.com/demo/image/upload/posters/old.jpg', 'posters');
        });

        $this->actingAs($this->manager(), 'sanctum')->deleteJson("/api/admin/posters/{$poster->id}")->assertOk();

        $this->assertModelMissing($poster);
    }

    public function test_adding_editing_and_deleting_notify_no_one(): void
    {
        $this->user(User::ROLE_ADMIN);

        $this->upload(['image' => $this->image(), 'expires_at' => '2026-12-02'])->assertCreated();
        $poster = Poster::first();
        $this->upload(['is_active' => '0'], $poster)->assertOk();
        $this->actingAs($this->manager(), 'sanctum')->deleteJson("/api/admin/posters/{$poster->id}")->assertOk();

        $this->assertSame(0, Notification::count());
        Event::assertNotDispatched(NotificationBroadcast::class);
    }

    // -----------------------------------------------------------------
    // The expiry warning
    // -----------------------------------------------------------------

    public function test_staff_are_warned_the_day_before_a_poster_expires(): void
    {
        $agent = $this->user(User::ROLE_ADMIN);
        $manager = $this->manager();
        $customer = $this->user(User::ROLE_CUSTOMER);
        $this->user(User::ROLE_ADMIN, User::STATUS_SUSPENDED);

        $onDisplay = $this->poster(['expires_at' => '2026-12-02']);
        $switchedOff = $this->poster(['expires_at' => '2026-12-02', 'is_active' => false]);
        $this->poster(['expires_at' => '2026-12-01']);
        $this->poster(['expires_at' => '2026-12-03']);
        $this->poster();

        $this->artisan('posters:warn-expiring')->assertSuccessful();

        foreach ([$agent, $manager] as $staff) {
            $titles = Notification::where('user_id', $staff->id)->orderBy('id')->pluck('title')->all();

            $this->assertSame(["Poster #{$onDisplay->id} expires tomorrow", "Poster #{$switchedOff->id} expires tomorrow"], $titles);
        }

        $this->assertSame(0, Notification::where('user_id', $customer->id)->count());
        $this->assertSame(4, Notification::where('notification_type', WarnExpiringPosters::TYPE)->count());
        Event::assertDispatchedTimes(NotificationBroadcast::class, 4);
    }

    public function test_each_poster_is_warned_once_a_day_and_not_before_opening(): void
    {
        $this->user(User::ROLE_ADMIN);
        $this->poster(['expires_at' => '2026-12-02']);

        // 06:00 in Manila: before the 07:00 opening.
        $this->travelTo('2026-11-30 22:00:00');
        $this->artisan('posters:warn-expiring')->assertSuccessful();
        $this->assertSame(0, Notification::count());

        $this->travelTo('2026-12-01 02:00:00');
        $this->artisan('posters:warn-expiring')->assertSuccessful();
        $this->travelTo('2026-12-01 05:00:00');
        $this->artisan('posters:warn-expiring')->assertSuccessful();

        $this->assertSame(1, Notification::count());
    }

    public function test_a_date_set_to_tomorrow_later_in_the_day_is_still_warned(): void
    {
        $this->user(User::ROLE_ADMIN);
        $this->artisan('posters:warn-expiring')->assertSuccessful();

        $this->upload(['image' => $this->image(), 'expires_at' => '2026-12-02'])->assertCreated();

        $this->travelTo('2026-12-01 03:00:00');
        $this->artisan('posters:warn-expiring')->assertSuccessful();

        // The agent and the manager who added it.
        $this->assertSame(2, Notification::where('notification_type', WarnExpiringPosters::TYPE)->count());
    }
}
