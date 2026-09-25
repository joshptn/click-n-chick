<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $table = 'cart';

    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_ADVANCE = 'advance';

    public const STATUS_IMMEDIATE = 'active';

    public const STATUS_ADVANCE = 'advance';

    protected $fillable = [
        'user_id',
        'guest_token',
        'cart_status',
    ];

    public static function statusForMode(?string $mode): string
    {
        return $mode === self::MODE_ADVANCE ? self::STATUS_ADVANCE : self::STATUS_IMMEDIATE;
    }

    public function isAdvance(): bool
    {
        return $this->cart_status === self::STATUS_ADVANCE;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }
}
