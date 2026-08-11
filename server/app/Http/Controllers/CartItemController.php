<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CartItemController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum'),
        ];
    }

    private array $addonCategoryNames = ['Addons', 'Addon', 'Sides', 'Drinks'];
    private array $sideCategoryNames  = ['Sides'];
    private array $drinkCategoryNames = ['Drinks'];

    /**
     * Build cart array + total for API responses.
     */
    private function cartPayloadForUser($user): array
    {
        $cartItems = $user->Cart()
            ->with('food.categories', 'parent.food')
            ->get();

        $cartData = $cartItems->map(function ($item) {
            $food = $item->food;

            $isAddon = $food && $food->categories->contains(
                fn($cat) => in_array($cat->name, $this->addonCategoryNames, true),
            );

            $parentMainFoodId = null;
            if ($item->parent_cart_item_id && $item->relationLoaded('parent') && $item->parent) {
                $parentMainFoodId = $item->parent->food_id;
            }

            return [
                'id'                  => $item->id,
                'food_id'             => $item->food_id,
                'food_name'           => $food->food_name ?? 'Unknown',
                'price'               => $food->price ?? 0,
                'quantity'            => $item->quantity,
                'subtotal'            => ($food->price ?? 0) * $item->quantity,
                'thumbnail'           => $food->thumbnail,
                'is_addon'            => $isAddon,
                'parent_cart_item_id' => $item->parent_cart_item_id,
                'parent_food_id'      => $parentMainFoodId,
            ];
        });

        return [
            'cart'  => $cartData,
            'total' => $cartData->sum('subtotal'),
        ];
    }

    /**
     * True when the client is setting an absolute main-line quantity (cart screen).
     * Menu "add to cart" always sends `sides` and `drinks` keys (possibly empty arrays).
     */
    private function isAbsoluteQuantityUpdate(Request $request): bool
    {
        return !$request->has('sides') && !$request->has('drinks');
    }

    /**
     * Count how many units of a category are already attached to a main cart item.
     * Each distinct addon row contributes its own quantity toward the cap.
     *
     * e.g. drink1(qty=1) + drink2(qty=1) = 2 → uses the full cap of a qty-2 main item.
     */
    private function existingAddonCount($mainCartItem, array $categoryNames): int
    {
        return $mainCartItem->addons()
            ->with('food.categories')
            ->get()
            ->filter(fn($addon) =>
                $addon->food &&
                $addon->food->categories->contains(
                    fn($cat) => in_array($cat->name, $categoryNames, true)
                )
            )
            ->sum('quantity');
    }

    /**
     * Sync a batch of addon food items (sides or drinks) onto a main cart item,
     * respecting a per-category cap equal to the main item's quantity.
     *
     * - Each addon row's quantity counts toward the cap (drink1 qty=1 + drink2 qty=1 = 2).
     * - New additions are silently capped so the total never exceeds the main quantity.
     */
    private function syncAddons($user, $mainCartItem, array $addonFoods, array $categoryNames): void
    {
        $cap = (int) $mainCartItem->quantity;

        foreach ($addonFoods as $addonFood) {
            // How many of this category are already stored?
            $usedSlots = $this->existingAddonCount($mainCartItem, $categoryNames);
            $remaining = $cap - $usedSlots;

            if ($remaining <= 0) {
                // Cap reached — skip the rest of this category's batch silently.
                break;
            }

            $existing = $user->cart()->where([
                'parent_cart_item_id' => $mainCartItem->id,
                'food_id'             => $addonFood['id'],
            ])->first();

            if ($existing) {
                // Only add as many as the remaining slots allow.
                $add  = min(1, $remaining);
                $next = min((int) $existing->quantity + $add, $cap);
                $existing->quantity = $next;
                $existing->save();
            } else {
                $qty = min(1, $remaining);
                $user->cart()->create([
                    'food_id'             => $addonFood['id'],
                    'parent_cart_item_id' => $mainCartItem->id,
                    'quantity'            => $qty,
                ]);
            }
        }
    }

    /**
     * After the main quantity is reduced (absolute update), trim each category's
     * addon totals down to the new cap, reducing quantities starting from the last row.
     */
    private function capAddonQuantitiesToMain($mainCartItem): void
    {
        if (!$mainCartItem) {
            return;
        }

        $cap = (int) $mainCartItem->quantity;

        foreach ([$this->sideCategoryNames, $this->drinkCategoryNames] as $categoryNames) {
            $addons = $mainCartItem->addons()
                ->with('food.categories')
                ->get()
                ->filter(fn($addon) =>
                    $addon->food &&
                    $addon->food->categories->contains(
                        fn($cat) => in_array($cat->name, $categoryNames, true)
                    )
                );

            $remaining = $cap;

            foreach ($addons as $addon) {
                if ($remaining <= 0) {
                    $addon->delete();
                    continue;
                }

                $next = min((int) $addon->quantity, $remaining);
                $remaining -= $next;

                if ($next !== (int) $addon->quantity) {
                    $addon->quantity = $next;
                    $addon->save();
                }
            }
        }
    }

    public function addToCart(Request $request, $foodId)
    {
        $user = $request->user();

        $validated = $request->validate([
            'quantity'     => 'integer|min:1',
            'sides'        => 'sometimes|array',
            'drinks'       => 'sometimes|array',
            'sides.*.id'   => 'integer|exists:food,id',
            'sides.*.size' => 'string|nullable',
            'drinks.*.id'  => 'integer|exists:food,id',
            'drinks.*.size'=> 'string|nullable',
        ]);

        $addQty   = (int) ($validated['quantity'] ?? 1);
        $absolute = $this->isAbsoluteQuantityUpdate($request);

        $cartItem = $user->cart()
            ->where('food_id', $foodId)
            ->whereNull('parent_cart_item_id')
            ->first();

        if ($absolute) {
            // Cart screen: set quantity directly, then trim addons to fit.
            if ($cartItem) {
                $cartItem->quantity = $addQty;
                $cartItem->save();
            } else {
                $cartItem = $user->cart()->create([
                    'food_id'             => $foodId,
                    'parent_cart_item_id' => null,
                    'quantity'            => $addQty,
                ]);
            }

            $cartItem->load('addons.food.categories');
            $this->capAddonQuantitiesToMain($cartItem);

        } else {
            // Menu screen: increment main, then add sides/drinks up to per-category cap.
            if ($cartItem) {
                $cartItem->quantity += $addQty;
                $cartItem->save();
            } else {
                $cartItem = $user->cart()->create([
                    'food_id'             => $foodId,
                    'parent_cart_item_id' => null,
                    'quantity'            => $addQty,
                ]);
            }

            $sides  = $validated['sides']  ?? [];
            $drinks = $validated['drinks'] ?? [];

            if (!empty($sides)) {
                $this->syncAddons($user, $cartItem, $sides, $this->sideCategoryNames);
            }

            if (!empty($drinks)) {
                $this->syncAddons($user, $cartItem, $drinks, $this->drinkCategoryNames);
            }
        }

        $payload = $this->cartPayloadForUser($user);

        return response()->json(array_merge([
            'message'   => 'Food and add-ons added to cart successfully!',
            'cart_item' => $cartItem->fresh()->load('food'),
        ], $payload));
    }

    public function userCart(Request $request)
    {
        $user    = $request->user();
        $payload = $this->cartPayloadForUser($user);

        return response()->json($payload);
    }

    public function removeToCart(Request $request, $foodId)
    {
        $user = $request->user();

        $cartItem = $user->Cart()
            ->where('food_id', $foodId)
            ->whereNull('parent_cart_item_id')
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Food not found in cart.'], 404);
        }

        $cartItem->addons()->delete();
        $cartItem->delete();

        return response()->json(['message' => 'Food and its add-ons removed from cart!']);
    }
}