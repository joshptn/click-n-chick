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
        'address_id',
        'order_number',
        // Replaced the dropped 'type' enum column.
        'order_type',
        'scheduled_for',
        'status',
        'total_price',
        'subtotal',
        'discount_amount',
        'delivery_fee',
        'total_amount',
        // Was previously listed as the nonexistent 'paid_status'.
        'payment_status',
        'reference_id',
        'estimated_time_of_completion',
        'guest_name',
        'guest_phone',
        'guest_email',
        'full_address',
        'latitude',
        'longitude',
        'location',
        'proof_of_payment',
    ];

    protected function casts(): array
    {
        return [
            // Set means this is an advance order.
            'scheduled_for' => 'datetime',
            'total_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /** Null for guest orders. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Null for guest orders; the snapshot fields on this table are the record. */
    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** ORDERS ||--o| DISCOUNTS: at most one discount claim per order. */
    public function discount()
    {
        return $this->hasOne(Discount::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function stockHolds()
    {
        return $this->hasMany(StockHold::class);
    }
}
