<?php

use App\Models\Order;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{orderId}', function ($user, $orderId) {
    $order = Order::find($orderId);

    if (! $order) {
        return false;
    }

    $isOwner = $order->user_id !== null
        && (int) $order->user_id === (int) $user->id;

    $isAdmin = in_array($user->role, ['admin', 'super_admin'], true);

    return $isOwner || $isAdmin;
});


Broadcast::channel('admin.orders', function ($user) {
    return in_array($user->role, ['admin', 'super_admin'], true);
});

Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
