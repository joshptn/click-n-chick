<?php

namespace App\Http\Resources;

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
            'prep_time' => $this->prep_time,
            'is_best_seller' => (bool) $this->is_best_seller,

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
            // 'Sides'), so the client does not re-derive the grouping.
            'addon_groups' => $this->whenLoaded('addons', fn () => $this->addons
                ->where('availability', true)
                ->groupBy('addon_group')
                ->map(fn ($addons, $group) => [
                    'group' => $group,
                    'addons' => $addons->map(fn ($addon) => [
                        'id' => $addon->id,
                        'addon_name' => $addon->addon_name,
                        'addon_price' => (float) $addon->addon_price,
                    ])->values(),
                ])
                ->values()),
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
