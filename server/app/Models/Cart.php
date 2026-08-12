<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    // The table is singular; Laravel would otherwise look for "carts".
    protected $table = 'cart';

    protected $fillable = [
        'user_id',
        'guest_token',
        'cart_status',
    ];

    /** Null user_id + a guest_token means this is a guest cart. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }
}
