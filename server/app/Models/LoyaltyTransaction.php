<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'points_change',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            // Positive = earned, negative = redeemed.
            'points_change' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Null for manual adjustments not tied to an order. */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
