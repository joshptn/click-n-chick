<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\PushToken;
use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The push copy of one in-app notification (PRD §9): every notification a
 * customer receives also goes to their installed PWA and native app.
 *
 * Tried once: FCM has no idempotency key, so a retry after a partial send would
 * buzz the devices that already got it a second time.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** @param  array<string, string|int|null>  $data */
    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    /**
     * Queue the push for a notification row, if the account has a device to
     * receive it. Most do not (staff, customers on the website), and checking
     * here keeps a job off the queue for each of those.
     *
     * Never throws: the notification is already saved and broadcast.
     */
    public static function forNotification(Notification $notification): void
    {
        try {
            $userId = (int) $notification->user_id;

            if (! PushToken::query()->forCustomers()->where('user_id', $userId)->exists()) {
                return;
            }

            // After the surrounding transaction, if any: a rolled-back change
            // must not have reached anyone's phone.
            self::dispatch($userId, (string) $notification->title, (string) $notification->body, [
                'notification_id' => $notification->getKey(),
                'type' => $notification->notification_type,
                'order_id' => $notification->order_id,
            ])->afterCommit();
        } catch (Throwable $e) {
            Log::warning('Could not queue a push notification.', ['error' => $e->getMessage()]);
        }
    }

    public function handle(PushSender $sender): void
    {
        $sender->toUser($this->userId, $this->title, $this->body, $this->data);
    }
}
