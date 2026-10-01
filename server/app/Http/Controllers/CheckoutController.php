<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Services\Orders\CheckoutQuote;
use App\Services\Orders\DeliveryQuote;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private CheckoutQuote $checkout,
        private DeliveryQuote $delivery,
    ) {}

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
            'apply_discount' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        return response()->json($this->checkout->build($user, Cart::immediateFor($user), $validated));
    }
    
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
