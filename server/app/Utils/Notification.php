<?php

namespace App\Utils;

use App\Models\Notification as ModelsNotification;

class Notification
{
    
    public static function notify($type, $event, $user_id, $order = null)
    {
        $title = ucfirst($type) . ' ' . ucfirst($event);

        $body = match($event) {
            'create' => "Your order #{$order->id} has been placed successfully. We are preparing it now.",
            'approved' => "Good news! Your order #{$order->id} has been approved and is now being prepared.",
            'declined' => "We’re sorry. Your order #{$order->id} has been declined. Please contact support for assistance.",
            'completed' => "Your order #{$order->id} has been completed. Thank you for ordering with us!",
            'cancelled' => "Your order #{$order->id} has been cancelled successfully.",
            'update' => "Your order #{$order->id} ETC has been updated to {$order->estimated_time_of_completion} minutes.",
            default => "There is an update regarding your order #{$order->id}."
        };

        $notification = ModelsNotification::create([
            'user_id' => $user_id,
            'title'   => $title,
            'body'    => $body,
        ]);

        Websocket::broadcast('notify', 'notification',$notification, $user_id);
    }
}