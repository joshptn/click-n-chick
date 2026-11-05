<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One dish as the Store Agent's stock page sees it: the catalogue facts it may
 * read but not change (name, price, category - BR-29), and the stock facts it
 * manages.
 */
class StockItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'food_name' => $this->food_name,
            'thumbnail' => $this->thumbnail,
            'price' => (int) $this->price,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),

            'is_available' => (bool) $this->is_available,
            // Null: made to order, never sells out on a count.
            'stock_quantity' => $this->stock_quantity,
            'is_tracked' => $this->stock_quantity !== null,
            // Set aside for customers still paying; off the count once paid.
            'held_quantity' => $this->held_quantity_value,
            'available_quantity' => $this->available_quantity,
            'is_orderable' => $this->is_orderable,
            'stock_status' => $this->stock_status,

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
