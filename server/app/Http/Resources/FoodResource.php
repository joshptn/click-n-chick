<?php

namespace App\Http\Resources;

use App\Models\Addon;
use App\Models\Food;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The menu shape the storefront reads.
 *
 * `is_orderable` and `stock_status` are computed on the model, not here, so
 * the value the card greys out on is the same value CartController refuses on.
 */
class FoodResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'food_name' => $this->food_name,
            'description' => $this->description,
            'price' => (int) $this->price,
            'thumbnail' => $this->thumbnail,
            'is_best_seller' => (bool) $this->is_best_seller,
            // Whether it can take the Senior/PWD discount (FR-05.9).
            'is_discountable' => (bool) $this->is_discountable,

            // Availability, as three separate facts the UI renders differently:
            // whether it can be ordered at all, why not, and how urgent it is.
            'is_available' => (bool) $this->is_available,
            'stock_quantity' => $this->stock_quantity,
            'is_orderable' => $this->is_orderable,
            'stock_status' => $this->stock_status,

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ]),

            // Grouped the way the detail modal renders them ('Drinks',
            // 'Sides'), so the client does not re-derive the grouping. Only
            // what can be had today: a switched-off add-on, or one with every
            // size switched off, is not offered.
            'addon_groups' => $this->whenLoaded('addons', fn () => $this->addons
                ->filter(fn (Addon $addon) => $addon->availability && (! $addon->isSized() || $addon->firstAvailableSize() !== null))
                ->groupBy('addon_group')
                ->map(fn ($addons, $group) => [
                    'group' => $group,
                    'addons' => $addons->map(fn (Addon $addon) => self::offered($addon))->values(),
                ])
                ->values()),

            // Every add-on the dish is linked to, switched off or not, for the
            // Store Manager's product form.
            'addon_ids' => $this->whenLoaded('addons', fn () => $this->addons->pluck('id')->map(fn ($id) => (int) $id)->values()),
        ];
    }

    /**
     * One add-on as the customer is offered it. A sized one lists the sizes
     * still available, each at its own price, and `addon_price` is the lowest
     * of them, so a screen that knows nothing of sizes shows a "from" figure.
     */
    private static function offered(Addon $addon): array
    {
        $sizes = $addon->sizes->where('is_available', true)->values();

        return [
            'id' => $addon->id,
            'addon_name' => $addon->addon_name,
            'addon_price' => $sizes->isNotEmpty() ? (float) $sizes->min('price') : (float) $addon->addon_price,
            'sizes' => $sizes->map(fn ($size) => [
                'size' => $size->size,
                'label' => $size->label(),
                'price' => (float) $size->price,
            ])->values(),
        ];
    }

    /** Constants the client would otherwise hardcode. */
    public static function meta(): array
    {
        return [
            'low_stock_threshold' => Food::LOW_STOCK_THRESHOLD,
        ];
    }
}
