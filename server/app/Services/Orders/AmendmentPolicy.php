<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Store\StoreAvailability;

class AmendmentPolicy
{
    public function editable(Order $order): array
    {
        return [
            'address' => $this->canEditAddress($order),
            'note' => $this->canEditNote($order),
            'pickup_at' => $this->canEditPickupTime($order),
            'fulfilment_type' => false,
            'items' => false,
        ];
    }

    public function anyEditable(Order $order): bool
    {
        return in_array(true, $this->editable($order), true);
    }

    public function canConfirmDetails(Order $order): bool
    {
        if ($order->isAdvance() || $order->isGuest()) {
            return false;
        }

        return $order->details_confirmed_at === null
            && in_array((string) $order->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true);
    }

    public function canEditAddress(Order $order): bool
    {
        return $this->isDelivery($order) && $this->stillWithTheKitchen($order);
    }

    public function canEditNote(Order $order): bool
    {
        return $this->isDelivery($order) && $this->stillWithTheKitchen($order);
    }

    public function canEditPickupTime(Order $order): bool
    {
        return ! $this->isDelivery($order) && $this->stillWithTheKitchen($order);
    }

    private function isDelivery(Order $order): bool
    {
        return $order->order_type === StoreAvailability::TYPE_DELIVERY;
    }

    private function stillWithTheKitchen(Order $order): bool
    {
        if ($order->isAdvance() || $order->isGuest()) {
            return false;
        }

        return OrderStatus::isInLine((string) $order->status);
    }
}
