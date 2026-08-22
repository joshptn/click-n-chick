<?php

namespace App\Http\Controllers;

use App\Services\Orders\CheckoutQuote;
use App\Services\Orders\DeliveryQuote;
use Illuminate\Http\Request;

/**
 * The Dispatch step of checkout (UC-ORD-001/002/003/004).
 *
 * Read-only. Nothing here writes an order; it answers "what would this order
 * cost, and may it be placed" so the customer sees the same figures the
 * server will use when they finally place it.
 *
 * Called on every meaningful change - fulfilment type, pin, selection - which
 * is why it touches no external service: the delivery figures are Haversine
 * arithmetic against config, so a dragged map pin re-prices instantly and
 * without spending anyone's rate limit.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private CheckoutQuote $checkout,
        private DeliveryQuote $delivery,
    ) {}

    /** POST /api/checkout/quote */
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'fulfilment_type' => ['required', 'in:pickup,delivery'],
            'cart_item_ids' => ['nullable', 'array'],
            'cart_item_ids.*' => ['integer'],
            'address_id' => ['nullable', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'full_address' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:255'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'pickup_at' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json($this->checkout->build($request->user(), $validated));
    }

    /**
     * POST /api/delivery/quote
     *
     * Just the destination half: distance, service area, fee (UC-DEL-004/005/
     * 006). Split out from the checkout quote because the map re-prices on
     * every pin drag, and that has no business re-reading the cart.
     */
    public function deliveryQuote(Request $request)
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(
            $this->delivery->for((float) $validated['latitude'], (float) $validated['longitude'])
        );
    }
}
