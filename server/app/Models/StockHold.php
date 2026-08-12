<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'food_id',
        'order_id',
        'quantity',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function food()
    {
        return $this->belongsTo(Food::class);
    }

    /** Null until the hold is attached to a confirmed order. */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
