<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Store\StoreAvailability;

class AmendmentPolicy
{
    /**
     * Everything that can still be changed, in the shape the tracker reads.
     *
     * @return array<string, bool>
     */
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
        return OrderStatus::isInLine((string) $order->status);
    }
}
