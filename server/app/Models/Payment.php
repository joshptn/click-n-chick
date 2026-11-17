<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    /** A hosted checkout is open and has not taken money yet. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /** The checkout closed without taking money: it expired, or we cancelled it. */
    public const STATUS_CLOSED = 'closed';

    /**
     * Columns as used with Xendit (no new columns, by decision):
     *   provider_reference_id  the payment session id (ps-...)
     *   idempotency_key        the Xendit payment id (py-...), set once paid; unique,
     *                          so one payment can be recorded only once (FR-04.4)
     *   payment_method         QRPH or GCASH, once paid
     */
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

    public function scopePending(Builder $query): Builder
    {
        return $query->where('payments.payment_status', self::STATUS_PENDING);
    }

    /** The reference sent to Xendit; it names this row so a webhook can find it. */
    public function gatewayReference(): string
    {
        return 'cnc-order-'.$this->order_id.'-payment-'.$this->getKey();
    }

    /** The payment row a Xendit reference names, if it is one of ours. */
    public static function forGatewayReference(?string $reference): ?self
    {
        if (! is_string($reference) || ! preg_match('/^cnc-order-(\d+)-payment-(\d+)$/', $reference, $match)) {
            return null;
        }

        return static::query()->whereKey((int) $match[2])->where('order_id', (int) $match[1])->first();
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
