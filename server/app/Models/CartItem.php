<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    /** @use HasFactory<\Database\Factories\CartFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quantity',
        'food_id',
        'parent_cart_item_id', // Add this
        'type',                // 'side', 'drink', or null
        'size',                // optional size for drinks/sides
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function food()
    {
        return $this->belongsTo(Food::class);
    }

    // If this cart item has addons
    public function addons()
    {
        return $this->hasMany(CartItem::class, 'parent_cart_item_id');
    }

    // If this cart item is an addon, its parent
    public function parent()
    {
        return $this->belongsTo(CartItem::class, 'parent_cart_item_id');
    }
}
