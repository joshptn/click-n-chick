<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
