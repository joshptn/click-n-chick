<?php

namespace Tests\Feature;

use App\Events\StoreBroadcast;
use App\Models\Setting;
use App\Models\User;
use App\Services\Store\StoreAvailability;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * How a broadcast reaches Reverb (App\Events\LiveBroadcast).
 *
 * These watch the publish call itself rather than faking the event, because
 * what is under test is when that call happens and what a failed one does.
 */
class LiveBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $published = [];

    private function reverb(bool $up = true): void
    {
        $broadcasting = $this->app->make(BroadcastManager::class);

        $config = config('broadcasting.connections.reverb');
        $config['client_options']['handler'] = function (RequestInterface $request) use ($up) {
            if (! $up) {
                return Create::rejectionFor(new ConnectException('Connection refused', $request));
            }

            $this->published[] = json_decode((string) $request->getBody(), true);

            return Create::promiseFor(new Response(200, [], '{}'));
        };

        $broadcasting->connection('reverb')->setPusher($broadcasting->pusher($config));
    }

    private function agent(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_a_store_change_is_published_by_the_request_itself(): void
    {
        Queue::fake();
        $this->reverb();

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['delivery_enabled' => false])
            ->assertOk();

        Queue::assertNothingPushed();

        $this->assertCount(1, $this->published);
        $this->assertSame('status', $this->published[0]['name']);
        $this->assertSame('store', $this->published[0]['channel'] ?? $this->published[0]['channels'][0]);
        $this->assertFalse(json_decode($this->published[0]['data'], true)['store']['delivery_enabled']);
    }

    public function test_an_event_raised_in_a_transaction_waits_for_the_commit(): void
    {
        $this->reverb();

        DB::transaction(function () {
            StoreBroadcast::dispatch(app(StoreAvailability::class)->snapshot());

            $this->assertCount(0, $this->published, 'Published before the change was saved.');
        });

        $this->assertCount(1, $this->published);
    }

    public function test_a_rolled_back_change_announces_nothing(): void
    {
        $this->reverb();

        try {
            DB::transaction(function () {
                StoreBroadcast::dispatch(app(StoreAvailability::class)->snapshot());

                throw new RuntimeException('The change failed.');
            });
        } catch (RuntimeException) {
        }

        $this->assertCount(0, $this->published);
    }

    public function test_reverb_being_down_does_not_fail_the_change(): void
    {
        Exceptions::fake();
        $this->reverb(up: false);

        $this->actingAs($this->agent(), 'sanctum')
            ->patchJson('/api/admin/store/toggles', ['delivery_enabled' => false])
            ->assertOk();

        $this->assertFalse((bool) Setting::get(Setting::STORE_DELIVERY_ENABLED));

        Exceptions::assertReported(BroadcastException::class);
    }

    public function test_publishing_gives_up_quickly(): void
    {
        $reverb = config('broadcasting.connections.reverb');

        // The request that raised the event waits on this call. Pusher's own
        // default is 30 seconds.
        $this->assertLessThanOrEqual(1, $reverb['client_options']['connect_timeout']);
        $this->assertLessThanOrEqual(3, $reverb['options']['timeout']);
    }
}
