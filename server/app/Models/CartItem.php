<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    /** @use HasFactory<\Database\Factories\CartFactory> */
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'user_id',
        'quantity',
        'food_id',
        'parent_cart_item_id',
        // 'type' and 'size' were listed here previously but no such columns exist.
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /** Null while the legacy user_id path is still in use. */
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function food()
    {
        return $this->belongsTo(Food::class);
    }

    /**
     * Legacy add-on mechanism: an add-on is itself a cart_item pointing at a
     * parent. Superseded by selectedAddons() once the cart module is rebuilt.
     */
    public function addons()
    {
        return $this->hasMany(CartItem::class, 'parent_cart_item_id');
    }

    public function parent()
    {
        return $this->belongsTo(CartItem::class, 'parent_cart_item_id');
    }

    /** ERD add-on mechanism, via the cart_item_addons join table. */
    public function selectedAddons()
    {
        return $this->belongsToMany(Addon::class, 'cart_item_addons')
            ->withTimestamps();
    }

    public function addonSelections()
    {
        return $this->hasMany(CartItemAddon::class);
    }
}
