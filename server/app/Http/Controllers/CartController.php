<?php

namespace App\Http\Controllers;

use App\Models\Addon;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    private function cartFor(Request $request): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $request->user()->id, 'cart_status' => 'active'],
        );
    }

    public function index(Request $request)
    {
        return response()->json($this->payload($this->cartFor($request)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'food_id' => ['required', 'integer', 'exists:food,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'addon_ids' => ['sometimes', 'array'],
            'addon_ids.*' => ['integer', 'distinct', 'exists:addons,id'],
        ]);

        $food = Food::findOrFail($validated['food_id']);

        if (! $food->is_orderable) {
            return response()->json([
                'message' => $food->is_available
                    ? 'Sorry, '.$food->food_name.' just sold out.'
                    : $food->food_name.' is not available right now.',
                'error_code' => 'FOOD_UNAVAILABLE',
            ], 422);
        }

        $quantity = (int) ($validated['quantity'] ?? 1);

        $addonIds = $food->addons()
            ->whereIn('addons.id', $validated['addon_ids'] ?? [])
            ->where('availability', true)
            ->pluck('addons.id')
            ->sort()
            ->values()
            ->all();

        $cart = $this->cartFor($request);

        $item = DB::transaction(function () use ($cart, $food, $quantity, $addonIds) {
            $existing = $this->matchingLine($cart, $food->id, $addonIds);

            if ($existing) {
                $existing->quantity = $this->clampToStock($food, $existing->quantity + $quantity);
                $existing->save();

                return $existing;
            }

            $line = $cart->items()->create([
                'user_id' => $cart->user_id,
                'food_id' => $food->id,
                'quantity' => $this->clampToStock($food, $quantity),
            ]);

            $line->selectedAddons()->sync($addonIds);

            return $line;
        });

        return response()->json(array_merge(
            ['message' => $food->food_name.' added to your order.', 'cart_item_id' => $item->id],
            $this->payload($cart->fresh()),
        ), 201);
    }

    public function updateQuantity(Request $request, CartItem $cartItem)
    {
        $this->assertOwned($request, $cartItem);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $cart = $this->cartFor($request);

        if ($validated['quantity'] === 0) {
            $cartItem->selectedAddons()->detach();
            $cartItem->delete();

            return response()->json(array_merge(['message' => 'Item removed.'], $this->payload($cart->fresh())));
        }

        $food = $cartItem->food;

        if ($food && ! $food->is_orderable) {
            return response()->json([
                'message' => $food->food_name.' is no longer available.',
                'error_code' => 'FOOD_UNAVAILABLE',
            ], 422);
        }

        $cartItem->quantity = $this->clampToStock($food, $validated['quantity']);
        $cartItem->save();

        return response()->json($this->payload($cart->fresh()));
    }

    public function destroy(Request $request, CartItem $cartItem)
    {
        $this->assertOwned($request, $cartItem);

        $cart = $this->cartFor($request);

        $cartItem->selectedAddons()->detach();
        $cartItem->delete();

        return response()->json(array_merge(['message' => 'Item removed.'], $this->payload($cart->fresh())));
    }

    public function destroyMany(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $cart = $this->cartFor($request);

        DB::transaction(function () use ($cart, $validated) {
            $items = $cart->items()->whereIn('id', $validated['ids'])->get();

            foreach ($items as $item) {
                $item->selectedAddons()->detach();
                $item->delete();
            }
        });

        return response()->json(array_merge(['message' => 'Items removed.'], $this->payload($cart->fresh())));
    }

    public function clear(Request $request)
    {
        $cart = $this->cartFor($request);

        DB::transaction(function () use ($cart) {
            foreach ($cart->items as $item) {
                $item->selectedAddons()->detach();
                $item->delete();
            }
        });

        return response()->json(array_merge(['message' => 'Your order is empty.'], $this->payload($cart->fresh())));
    }

    private function matchingLine(Cart $cart, int $foodId, array $addonIds): ?CartItem
    {
        return $cart->items()
            ->where('food_id', $foodId)
            ->with('selectedAddons')
            ->get()
            ->first(fn (CartItem $item) => $item->selectedAddons
                ->pluck('id')->sort()->values()->all() === $addonIds);
    }

    private function clampToStock(?Food $food, int $quantity): int
    {
        if ($food === null || $food->stock_quantity === null) {
            return max(1, $quantity);
        }

        return max(1, min($quantity, $food->stock_quantity));
    }

    private function assertOwned(Request $request, CartItem $cartItem): void
    {
        abort_unless($cartItem->user_id === $request->user()->id, 403, 'That item is not in your cart.');
    }

    private function payload(Cart $cart): array
    {
        $items = $cart->items()->with(['food.category', 'selectedAddons'])->orderByDesc('id')->get();

        $lines = $items->map(function (CartItem $item) {
            $food = $item->food;
            $addons = $item->selectedAddons;

            $addonTotal = (float) $addons->sum('addon_price');
            $unitPrice = (float) ($food->price ?? 0) + $addonTotal;

            return [
                'id' => $item->id,
                'food_id' => $item->food_id,
                'food_name' => $food->food_name ?? 'Unavailable item',
                'thumbnail' => $food->thumbnail ?? null,
                'quantity' => $item->quantity,
                'base_price' => (float) ($food->price ?? 0),
                'addons' => $addons->map(fn (Addon $addon) => [
                    'id' => $addon->id,
                    'addon_name' => $addon->addon_name,
                    'addon_price' => (float) $addon->addon_price,
                ])->values(),
                'addons_total' => $addonTotal,
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice * $item->quantity,

                'is_orderable' => $food?->is_orderable ?? false,
                'stock_status' => $food?->stock_status,
                'stock_quantity' => $food?->stock_quantity,
            ];
        });

        return [
            'cart' => $lines,
            'item_count' => (int) $items->sum('quantity'),
            'line_count' => $lines->count(),
            'subtotal' => (float) $lines->sum('subtotal'),
            'total' => (float) $lines->sum('subtotal'),
            'has_unavailable_items' => $lines->contains(fn ($line) => ! $line['is_orderable']),
        ];
    }
}
