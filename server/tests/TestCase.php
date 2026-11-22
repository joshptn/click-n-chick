<?php

namespace Tests;

use App\Models\Order;
use App\Models\Payment;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

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
    /**
     * A confirmed payment for an order. Decided 2026-11-23: the payment record
     * is the only record that an order was paid, so a test that wants a paid
     * order writes one, as the settlement would.
     */
    protected function payFor(Order $order, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'order_id' => $order->getKey(),
            'user_id' => $order->user_id,
            'payment_method' => Payment::METHOD_GCASH,
            'payment_status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'idempotency_key' => 'py-test-'.Str::uuid(),
        ], $attributes));
    }

    /**
     * Order::create for tests that describe an order's payment the old way.
     * 'payment_status' => 'paid' becomes a confirmed payment, paid 20 minutes
     * earlier unless 'paid_at' says otherwise (so a placed order is already
     * past the store's 15 minutes); 'refund_owed' becomes that payment's
     * refund, a refund by hand. Anything else goes to Order::create as given.
     */
    protected function orderWith(array $attributes): Order
    {
        $paid = ($attributes['payment_status'] ?? null) === Payment::STATUS_PAID;
        $paidAt = $attributes['paid_at'] ?? null;
        $refund = $attributes['refund_owed'] ?? null;

        unset($attributes['payment_status'], $attributes['paid_at'], $attributes['refund_owed']);

        $order = Order::create($attributes);

        if ($paid) {
            $this->payFor($order, [
                'paid_at' => $paidAt ?? now()->subMinutes(20),
                'refund_amount' => $refund,
                'refund_status' => $refund !== null && (float) $refund > 0 ? Payment::REFUND_MANUAL : null,
            ]);
        }

        return $order;
    }

    protected function enableSmsChannel(): void
    {
        config([
            'services.sms.driver' => 'semaphore',
            'services.semaphore.key' => 'test-key',
        ]);
    }
}
