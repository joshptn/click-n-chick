<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Units of one food set aside for one order (GATE-7).
 *
 * holding:   reserved while the customer pays; counts against what is left to
 *            sell but has not left the count yet. Lapses at expires_at.
 * confirmed: sold. The units have come off foods.stock_quantity.
 * released:  given back - a hold the customer never paid for, or a sale
 *            cancelled before the kitchen started (restocked).
 * expired:   a hold whose payment window closed unpaid.
 *
 * Written only by App\Services\Stock\StockReservation.
 */
class StockHold extends Model
{
    use HasFactory;

    public const HOLDING = 'holding';

    public const CONFIRMED = 'confirmed';

    public const RELEASED = 'released';

    public const EXPIRED = 'expired';

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

    /** Holds still standing between the count and a customer. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::HOLDING)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function food()
    {
        return $this->belongsTo(Food::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
