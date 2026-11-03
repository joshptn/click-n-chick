<?php

namespace Tests;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Answer Reverb's publish call in memory.
     *
     * The queue is sync under test, so every unfaked broadcast is published
     * inline. With no Reverb listening, each one waited for a refused
     * connection - about two seconds on Windows - and the suite's run time
     * depended on whether a dev server happened to be up. The driver stays
     * Reverb, so channel auth is still signed for real and payloads are still
     * built and encoded; only the HTTP round trip is replaced.
     *
     * The client is swapped on the existing broadcaster rather than set through
     * config: registering routes/channels.php builds the broadcaster during
     * boot, and the channel definitions live on that instance.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $broadcasting = $this->app->make(BroadcastManager::class);

        $config = config('broadcasting.connections.reverb');
        $config['client_options']['handler'] = static fn () => Create::promiseFor(new Response(200, [], '{}'));

        $broadcasting->connection('reverb')->setPusher($broadcasting->pusher($config));
    }

    /**
     * Model a deployment with a working SMS provider.
     *
     * phpunit.xml pins SMS_DRIVER=log, so the phone channel reports itself
     * undeliverable by default and the flows that guard on that refuse it.
     * Any test that exercises the phone channel is describing a deployment
     * where the provider IS configured, and says so with this. The sender is
     * still swapped for a fake - see AppServiceProvider, which never hands out
     * SemaphoreClient while running tests - so no message leaves the machine
     * and no credits are spent.
     */
    protected function enableSmsChannel(): void
    {
        config([
            'services.sms.driver' => 'semaphore',
            'services.semaphore.key' => 'test-key',
        ]);
    }
}
