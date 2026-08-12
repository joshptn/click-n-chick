<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'food_id',
        'quantity',
        'price',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function food()
    {
        return $this->belongsTo(Food::class);
    }

    /** Add-ons selected for this line, with the price snapshot on the pivot. */
    public function addons()
    {
        return $this->belongsToMany(Addon::class, 'order_item_addons')
            ->withPivot('unit_price')
            ->withTimestamps();
    }

    public function addonSelections()
    {
        return $this->hasMany(OrderItemAddon::class);
    }
}
