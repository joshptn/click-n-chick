<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'discount_type',
        'discount_percentage',
        'vat_exempt',
        'vat_exempt_amount',
        'id_image',
        'discount_status',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'vat_exempt' => 'boolean',
            'vat_exempt_amount' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** Null for guest discount claims. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
