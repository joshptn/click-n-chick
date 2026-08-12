<?php

namespace App\Models;

use App\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Food extends Model
{
    /** @use HasFactory<\Database\Factories\FoodFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'thumbnail',
        'food_name',
        'price',
        'stock_quantity',
        'is_available',
        'prep_time',
        'available',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            // Null means the item is not stock-tracked.
            'stock_quantity' => 'integer',
            'prep_time' => 'integer',
            // Manual override toggle, distinct from the legacy 'available'.
            'is_available' => 'boolean',
            'available' => 'boolean',
        ];
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * ERD: CATEGORIES ||--o{ FOOD. Replaced the former belongsToMany over the
     * category_food pivot, which has been retired.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /** Add-ons offered for this item, via the addon_food join table. */
    public function addons()
    {
        return $this->belongsToMany(Addon::class, 'addon_food')
            ->withTimestamps();
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockHolds()
    {
        return $this->hasMany(StockHold::class);
    }
}
