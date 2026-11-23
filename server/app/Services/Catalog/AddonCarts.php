<?php

namespace App\Services\Catalog;

use App\Models\Addon;
use App\Models\CartItem;
use App\Models\CartItemAddon;
use App\Models\Food;

/**
 * Keeps carts to what the catalogue still offers, after the Store Manager
 * changes an add-on or a dish's add-ons (decided 2026-11-24).
 *
 * A pick comes out of a cart when its add-on is deleted, is no longer offered
 * with that dish, or no longer comes in the size picked, as a deleted dish
 * leaves carts. An add-on that stops coming in sizes keeps its picks, as the
 * add-on alone. A switched-off add-on or size stays: it may come back, and
 * checkout says why it cannot go through meanwhile. Carts float, so the line
 * is priced again on its next read, and the shown-total check stops a total
 * the customer has not seen.
 */
final class AddonCarts
{
    public function tidy(Addon $addon): void
    {
        $picks = CartItemAddon::query()->where('addon_id', $addon->getKey());

        if ($addon->trashed()) {
            $picks->delete();

            return;
        }

        $offeredWith = $addon->foods()->pluck('food.id')->map(fn ($id) => (int) $id)->all();

        (clone $picks)
            ->whereNotIn('cart_item_id', CartItem::query()->whereIn('food_id', $offeredWith)->select('id'))
            ->delete();

        $sizes = $addon->sizes()->pluck('size')->all();

        if ($sizes === []) {
            (clone $picks)->whereNotNull('size')->update(['size' => null]);

            return;
        }

        (clone $picks)
            ->where(fn ($query) => $query->whereNull('size')->orWhereNotIn('size', $sizes))
            ->delete();
    }

    /** After a dish's add-ons change: picks of the ones it no longer offers. */
    public function tidyDish(Food $food): void
    {
        $offered = $food->addons()->pluck('addons.id')->map(fn ($id) => (int) $id)->all();

        CartItemAddon::query()
            ->whereIn('cart_item_id', CartItem::query()->where('food_id', $food->getKey())->select('id'))
            ->whereNotIn('addon_id', $offered)
            ->delete();
    }
}
