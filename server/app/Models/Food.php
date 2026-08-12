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

   
}
