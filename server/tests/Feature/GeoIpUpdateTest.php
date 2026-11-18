<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use Tests\TestCase;

/** `geoip:update`: keeps the GeoLite2 City file on disk, downloading only when needed. */
class GeoIpUpdateTest extends TestCase
{
    private string $directory;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/geoip-'.uniqid());
        $this->database = $this->directory.'/GeoLite2-City.mmdb';

        config([
            'services.maxmind.account_id' => '123456',
            'services.maxmind.license_key' => 'test-licence-key',
            'services.maxmind.database' => $this->database,
            'services.maxmind.max_age_days' => 7,
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** MaxMind's download: a tar.gz holding one dated folder with the .mmdb and licence files. */
    private function archive(string $databaseBytes): string
    {
        $work = storage_path('framework/testing/geoip-archive-'.uniqid());
        File::ensureDirectoryExists($work);

        $tar = new PharData($work.'/db.tar');
        $tar->addFromString('GeoLite2-City_20261118/LICENSE.txt', 'licence');
        $tar->addFromString('GeoLite2-City_20261118/GeoLite2-City.mmdb', $databaseBytes);
        $tar->compress(\Phar::GZ);
        unset($tar);

        $bytes = (string) file_get_contents($work.'/db.tar.gz');
        File::deleteDirectory($work);

        return $bytes;
    }

    private function validDatabase(): string
    {
        return str_repeat("\x00", 64)."\xAB\xCD\xEFMaxMind.com".str_repeat("\x01", 16);
    }

    public function test_it_downloads_the_database_when_none_is_on_disk(): void
    {
        Http::fake(['download.maxmind.com/*' => Http::response($this->archive($this->validDatabase()))]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertFileExists($this->database);
        $this->assertSame($this->validDatabase(), file_get_contents($this->database));

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download')
                && str_contains($request->url(), 'suffix=tar.gz')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('123456:test-licence-key'));
        });

        // Nothing is left behind beside the database.
        $this->assertSame(['GeoLite2-City.mmdb'], array_map('basename', File::files($this->directory)));
    }

    public function test_a_fresh_copy_is_not_downloaded_again(): void
    {
        File::ensureDirectoryExists($this->directory);
        File::put($this->database, 'current');
        Http::fake();

        $this->artisan('geoip:update')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('current', file_get_contents($this->database));
    }

    public function test_a_copy_older_than_the_limit_is_replaced(): void
    {
        File::ensureDirectoryExists($this->directory);
        File::put($this->database, 'old');
        touch($this->database, now()->subDays(8)->getTimestamp());

        Http::fake(['download.maxmind.com/*' => Http::response($this->archive($this->validDatabase()))]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertSame($this->validDatabase(), file_get_contents($this->database));
    }

    public function test_without_credentials_it_does_nothing(): void
    {
        config(['services.maxmind.license_key' => null]);
        Http::fake();

        $this->artisan('geoip:update')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->database);
    }

    public function test_a_refused_download_keeps_the_old_copy_and_waits_an_hour(): void
    {
        File::ensureDirectoryExists($this->directory);
        File::put($this->database, 'old');
        touch($this->database, now()->subDays(8)->getTimestamp());

        Http::fake(['download.maxmind.com/*' => Http::response('Unauthorized', 401)]);

        $this->artisan('geoip:update')->assertFailed();
        $this->assertSame('old', file_get_contents($this->database));

        // The next scheduled run does not try again straight away.
        $this->artisan('geoip:update')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_a_file_that_is_not_a_maxmind_database_is_refused(): void
    {
        Http::fake(['download.maxmind.com/*' => Http::response($this->archive('<html>not a database</html>'))]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertFileDoesNotExist($this->database);
        $this->assertSame([], File::files($this->directory));
    }
}
