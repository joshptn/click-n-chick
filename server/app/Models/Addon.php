<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Addon extends Model
{
    use HasFactory;

    protected $fillable = [
        'addon_name',
        'addon_price',
        'availability',
        'addon_group',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'addon_price' => 'decimal:2',
            'availability' => 'boolean',
        ];
    }

    /** Which food items offer this add-on. */
    public function foods()
    {
        return $this->belongsToMany(Food::class, 'addon_food');
    }

    public function cartItems()
    {
        return $this->belongsToMany(CartItem::class, 'cart_item_addons');
    }

    public function orderItems()
    {
        return $this->belongsToMany(OrderItem::class, 'order_item_addons')
            ->withPivot('unit_price');
    }
}
