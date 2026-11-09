<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PAID = 'paid';

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
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'refund_amount' => 'decimal:2',
        ];
    }

    /** Money confirmed as received: a paid record with the moment it was paid. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('payments.payment_status', self::STATUS_PAID)->whereNotNull('payments.paid_at');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
