<?php

namespace App\Utils;

use App\Events\NotificationBroadcast;
use App\Models\Notification as ModelsNotification;
use App\Models\Order;
use App\Services\Orders\OrderNotice;

class Notification
{

    public static function push(
        int $userId,
        string $title,
        string $body,
        ?string $type = null,
        ?Order $order = null,
    ): ModelsNotification {
        $notification = ModelsNotification::create([
            'user_id' => $userId,
            'order_id' => $order?->getKey(),
            'title' => $title,
            'body' => $body,
            'notification_type' => $type,
        ]);

        NotificationBroadcast::dispatch($notification, $userId);

        return $notification;
    }

    /** @param  array{title: string, body: string, type?: string|null}  $notice */
    public static function send(int $userId, array $notice, ?Order $order = null): ModelsNotification
    {
        return self::push($userId, $notice['title'], $notice['body'], $notice['type'] ?? null, $order);
    }

    /** Tell a customer about their own order, worded for the kind of order it is. */
    public static function notify($type, $event, $user_id, $order = null)
    {
        if (! $order instanceof Order) {
            return self::push((int) $user_id, ucfirst((string) $type), 'There is an update on your order.');
        }

        return self::send((int) $user_id, app(OrderNotice::class)->customer($order, (string) $event), $order);
    }
}
