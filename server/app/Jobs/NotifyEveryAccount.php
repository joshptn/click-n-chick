<?php

namespace App\Jobs;

use App\Events\NotificationBroadcast;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One notice to every account, written and broadcast by the queue worker.
 *
 * Each account is a notification row plus a publish to Reverb. Inside the
 * request that made the change, that grows with the number of accounts, and a
 * request killed partway leaves the rest with no notification at all.
 *
 * Tried once: a retry would start again from the first account and notify
 * everyone already reached a second time.
 */
class NotifyEveryAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Under the database queue's retry_after (90 s), past which it would be handed out again. */
    public int $timeout = 80;

    public function __construct(
        public string $title,
        public string $body,
    ) {}

    public function handle(): void
    {
        User::query()
            ->select('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $notification = Notification::create([
                        'user_id' => $user->id,
                        'title' => $this->title,
                        'body' => $this->body,
                        'is_read' => false,
                    ]);

                    NotificationBroadcast::dispatch($notification, (int) $user->id);
                }
            });
    }
}
