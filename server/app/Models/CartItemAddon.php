<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItemAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_item_id',
        'addon_id',
    ];

    public function cartItem()
    {
        return $this->belongsTo(CartItem::class);
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }
}
