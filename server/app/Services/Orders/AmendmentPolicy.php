<?php

namespace App\Services\Orders;

use App\Models\Order;

/**
 * What a customer may change on an order (BR-21b, decided 2026-11-17).
 *
 * Only the contact name and mobile number, until the order closes, on every
 * kind of order, by the customer or a guest from their tracking link. Never
 * the address (it could move the delivery fee), the rider's note, the
 * collection time, the items or pickup/delivery. A Store Agent changes none
 * of it.
 */
class AmendmentPolicy
{
    /** @return array<string, bool> */
    public function editable(Order $order): array
    {
        $contact = $this->canEditContact($order);

        return [
            'contact_name' => $contact,
            'contact_phone' => $contact,
            'address' => false,
            'note' => false,
            'pickup_at' => false,
            'fulfilment_type' => false,
            'items' => false,
        ];
    }

    public function canEditContact(Order $order): bool
    {
        // An unpaid checkout is not an order yet; a closed one is history.
        return ! $order->isTerminal() && ! $order->isUnpaidCheckout();
    }

    /**
     * "Details are correct" (UC-ORD-009) is hidden for now, pending the
     * client's decision (2026-11-17). Nothing about it is removed.
     */
    public function canConfirmDetails(Order $order): bool
    {
        return false;
    }
}
