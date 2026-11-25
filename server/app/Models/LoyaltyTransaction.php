<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the points ledger (decided 2026-11-24, BES Loyalty Program).
 * users.loyalty_points is the running balance; this is every point given and
 * spent, which the Store Manager's totals are read from.
 *
 * A row outlives its account (user_id null after a deletion), so the totals
 * issued and redeemed never drop.
 */
class LoyaltyTransaction extends Model
{
    use HasFactory;

    /** Credited when a paid order is completed or delivered. */
    public const EARNED = 'earned';

    /** Taken when an order is paid (partly or wholly) with points. */
    public const REDEEMED = 'redeemed';

    /** Given back when an order paid with points is cancelled or declined. */
    public const RETURNED = 'returned';

    protected $fillable = [
        'user_id',
        'order_id',
        'points_change',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            // Positive = earned or returned, negative = redeemed. Centavos kept.
            'points_change' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
