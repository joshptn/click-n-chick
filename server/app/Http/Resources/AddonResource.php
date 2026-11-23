<?php

namespace App\Http\Resources;

use App\Models\Addon;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One add-on as the staff pages see it (decided 2026-11-24): the Store
 * Manager's catalogue facts, and the switches the Store Agent manages - the
 * add-on itself and each of its sizes.
 *
 * @mixin Addon
 */
class AddonResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'addon_name' => $this->addon_name,
            'addon_group' => $this->addon_group,
            // A sized add-on's lowest size price; its sizes carry their own.
            'addon_price' => (float) $this->addon_price,
            'is_available' => (bool) $this->availability,
            'is_sized' => $this->isSized(),
            'sizes' => $this->sizes->map(fn ($size) => [
                'size' => $size->size,
                'label' => $size->label(),
                'price' => (float) $size->price,
                'is_available' => (bool) $size->is_available,
            ])->values(),
            // The dishes that offer it.
            'food_ids' => $this->whenLoaded('foods', fn () => $this->foods->pluck('id')->map(fn ($id) => (int) $id)->values()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
