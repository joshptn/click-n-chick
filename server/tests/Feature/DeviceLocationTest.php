<?php

namespace Tests\Feature;

use App\Models\KnownDevice;
use App\Models\User;
use App\Services\Geo\IpLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The approximate place beside each device (decided 2026-11-18): read from the
 * local GeoLite2 database by IpLocator, computed when the list is shown and
 * never stored.
 */
class DeviceLocationTest extends TestCase
{
    use RefreshDatabase;

    private function deviceFor(User $user, string $fingerprint, ?string $ip): KnownDevice
    {
        return KnownDevice::create([
            'user_id' => $user->id,
            'device_fingerprint' => $fingerprint,
            'device_name' => 'Click n Chick app on Android',
            'platform' => 'Android',
            'last_ip_address' => $ip,
            'last_seen_at' => now(),
        ]);
    }

    public function test_each_device_carries_its_approximate_location(): void
    {
        $user = User::factory()->create();
        $this->deviceFor($user, 'phone', '203.0.113.9');
        $this->deviceFor($user, 'laptop', null);

        $locator = Mockery::mock(IpLocator::class);
        $locator->shouldReceive('locate')->with('203.0.113.9')->andReturn('Apalit, Philippines');
        $locator->shouldReceive('locate')->with(null)->andReturn(null);
        $this->app->instance(IpLocator::class, $locator);

        Sanctum::actingAs($user);

        $devices = collect($this->getJson('/api/user/devices')->assertOk()->json('devices'));

        $this->assertSame('Apalit, Philippines', $devices->firstWhere('last_ip_address', '203.0.113.9')['location']);
        $this->assertNull($devices->firstWhere('last_ip_address', null)['location']);
    }

    public function test_without_the_database_the_list_still_loads_with_no_location(): void
    {
        config(['services.maxmind.database' => storage_path('framework/testing/missing.mmdb')]);

        $user = User::factory()->create();
        $this->deviceFor($user, 'phone', '8.8.8.8');

        Sanctum::actingAs($user);

        $this->getJson('/api/user/devices')
            ->assertOk()
            ->assertJsonPath('devices.0.location', null);
    }

    public function test_private_and_missing_addresses_have_no_location(): void
    {
        $locator = new IpLocator;

        $this->assertNull($locator->locate(null));
        $this->assertNull($locator->locate(''));
        $this->assertNull($locator->locate('127.0.0.1'));
        $this->assertNull($locator->locate('192.168.1.20'));
        $this->assertNull($locator->locate('10.0.2.2'));
    }
}
