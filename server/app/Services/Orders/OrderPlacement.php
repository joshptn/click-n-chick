<?php

namespace App\Services\Orders;

use App\Exceptions\DiscountAlreadyUsed;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\User;
use App\Services\Stock\StockReservation;
use App\Services\Store\StoreAvailability;
use Illuminate\Support\Facades\DB;

class OrderPlacement
{
    public function __construct(
        private DiscountUsage $discountUsage,
        private StockReservation $stock,
    ) {}

    /**
     * @param  Cart  $cart  Non-null by the time this is reached: an empty selection
     *                      is a blocker, so can_place being true means a cart exists.
     *
     * @throws DiscountAlreadyUsed
     */
    public function place(?User $user, Cart $cart, array $quote): Order
    {
        $isDelivery = $quote['fulfilment_type'] === StoreAvailability::TYPE_DELIVERY;
        $destination = $quote['destination'];
        $contact = $quote['contact'];
        $isGuest = $user === null;

        return DB::transaction(function () use (
            $user,
            $quote,
            $isDelivery,
            $destination,
            $contact,
            $isGuest
        ) {
            // Re-checked inside the transaction rather than trusting the quote,
            // because the quote was built before this one was.
            if (! $isGuest
                && (float) $quote['discount']['amount'] > 0
                && $this->discountUsage->usedToday($user)) {
                throw new DiscountAlreadyUsed;
            }

            $order = Order::create([
                'user_id' => $user?->getKey(),
                'order_type' => $quote['fulfilment_type'],
                'address_id' => $isDelivery ? ($destination['address_id'] ?? null) : null,
                // Paid before it is placed (FR-04.5): it waits here, unseen by
                // staff, until the payment arrives (PaymentSettlement).
                'status' => OrderStatus::AWAITING_PAYMENT,
                'subtotal' => $quote['subtotal'],
                'discount_amount' => $quote['discount']['amount'],
                'delivery_fee' => $quote['delivery_fee'],
                'delivery_distance_km' => $isDelivery ? ($quote['delivery']['distance_km'] ?? null) : null,
                'total_amount' => $quote['total'],
                'total_price' => $quote['total'],
                'pickup_at' => $isDelivery ? null : ($quote['pickup']['requested_at'] ?? null),
                'full_address' => $isDelivery ? ($destination['full_address'] ?? null) : null,
                'latitude' => $isDelivery ? ($destination['latitude'] ?? null) : null,
                'longitude' => $isDelivery ? ($destination['longitude'] ?? null) : null,
                'location' => $isDelivery ? ($destination['locality'] ?? null) : null,
                'delivery_note' => $isDelivery ? ($destination['delivery_note'] ?? null) : null,
                'payment_status' => Order::PAYMENT_UNPAID,
                // Only a guest order carries its contact on the row. An account
                // order reads it back off the user, so copying it here as well
                // would be a second version free to drift from the first.
                'guest_name' => $isGuest ? $contact['name'] : null,
                'guest_phone' => $isGuest ? $contact['phone'] : null,
                'guest_email' => $isGuest ? ($contact['email'] ?: null) : null,
            ]);

            foreach ($quote['items'] as $line) {
                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'food_id' => $line['food_id'],
                    'quantity' => $line['quantity'],
                    'price' => $line['unit_price'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $line['subtotal'],
                ]);

                foreach ($line['addons'] ?? [] as $addon) {
                    OrderItemAddon::create([
                        'order_item_id' => $item->id,
                        'addon_id' => $addon['id'],
                        'unit_price' => $addon['addon_price'],
                    ]);
                }
            }

            // Under the food rows' lock, so the last units go to one customer
            // (FR-08.3); a shortage rolls the whole order back. Only held: the
            // sale is the payment, which confirms the hold, and an unpaid hold
            // lapses with the payment window (FR-04.5).
            $this->stock->hold($order, $quote['items']);

            return $order;
        });
    }

    /**
     * Take the ordered lines out of the cart, once the checkout is open.
     *
     * Separate from place() so that a checkout the payment provider could not
     * open leaves the cart as it was, for the customer to try again.
     */
    public function clearCart(Cart $cart, array $quote): void
    {
        $cart->items()->whereIn('id', collect($quote['items'])->pluck('id')->all())->delete();
    }
}
