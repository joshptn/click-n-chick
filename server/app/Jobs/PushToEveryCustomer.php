<?php

namespace App\Jobs;

use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The push copy of a notice to every account (price, delivery-fee and
 * discount-rate changes; FR-06.5, FR-07.8). Its own job, after NotifyEveryAccount
 * has written the in-app rows, so neither shares the other's time limit.
 *
 * Tried once, for the same reason as NotifyEveryAccount: a retry would push to
 * every device already reached a second time.
 */
class PushToEveryCustomer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Under the database queue's retry_after (90 s), past which it would be handed out again. */
    public int $timeout = 80;

    public function __construct(
        public string $title,
        public string $body,
    ) {}

    public function handle(PushSender $sender): void
    {
        $sender->toEveryCustomer($this->title, $this->body, ['type' => 'announcement']);
    }
}
