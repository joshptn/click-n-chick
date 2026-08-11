<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    
    protected $fillable = [
        'user_id',
        'status',      
        'total_price',
        'paid_status',
        'type',
        'reference_id',
        'estimated_time_of_completion',
        'latitude',
        'longitude',
        'location',
        'proof_of_payment'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
