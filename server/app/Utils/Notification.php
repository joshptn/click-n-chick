<?php

namespace App\Utils;

use App\Events\NotificationBroadcast;
use App\Models\Notification as ModelsNotification;
use App\Services\Orders\OrderStatus;

class Notification
{
    public static function notify($type, $event, $user_id, $order = null)
    {
        $title = ucfirst($type).' '.(
            is_string($event) && OrderStatus::isKnown($event)
                ? OrderStatus::label($event)
                : ucfirst((string) $event)
        );

        $body = match ($event) {
            'create' => "Your order #{$order->id} has been placed. We will confirm it shortly.",
            OrderStatus::CONFIRMED => "Good news! Your order #{$order->id} has been confirmed.",
            OrderStatus::PREPARING => "Your order #{$order->id} is being prepared now.",
            OrderStatus::READY_FOR_PICKUP => "Your order #{$order->id} is ready for collection at the store.",
            OrderStatus::ON_THE_WAY => "Your order #{$order->id} is on the way.",
            OrderStatus::COMPLETED => "Your order #{$order->id} has been completed. Thank you for ordering with us!",
            OrderStatus::DELIVERED => "Your order #{$order->id} has been delivered. Thank you for ordering with us!",
            OrderStatus::CANCELLED => "Your order #{$order->id} has been cancelled.",
            'update' => "Your order #{$order->id} ETC has been updated to {$order->estimated_time_of_completion} minutes.",
            default => "There is an update regarding your order #{$order->id}."
        };

        $notification = ModelsNotification::create([
            'user_id' => $user_id,
            'title' => $title,
            'body' => $body,
        ]);

        NotificationBroadcast::dispatch($notification, (int) $user_id);
    }
}
