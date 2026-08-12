<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'payment_method',
        'payment_status',
        'provider_reference_id',
        'idempotency_key',
        'paid_at',
        'refund_amount',
        'refund_status',
        'refund_reference_id',
        'refunded_at',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'verified_at' => 'datetime',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** Null for guest payments. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The admin who confirmed a manual payment. */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
