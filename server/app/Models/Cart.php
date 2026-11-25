<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Cart extends Model
{
    use HasFactory;

    protected $table = 'cart';

    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_ADVANCE = 'advance';

    /** The most of one line today's cart takes; stock caps it lower. */
    public const MAX_QUANTITY = 99;

    /**
     * The most of one line an advance cart takes: four digits, like the Store
     * Agent's stock count (decided 2026-11-26), for large orders booked ahead.
     */
    public const ADVANCE_MAX_QUANTITY = 9999;

    public static function maxQuantity(bool $advance): int
    {
        return $advance ? self::ADVANCE_MAX_QUANTITY : self::MAX_QUANTITY;
    }

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

    public static function immediateFor(User $user): ?self
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->where('cart_status', self::STATUS_IMMEDIATE)
            ->first();
    }

    public static function forGuestToken(?string $token): ?self
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return static::query()
            ->whereNull('user_id')
            ->where('guest_token', $token)
            ->where('cart_status', self::STATUS_IMMEDIATE)
            ->first();
    }

    public static function startForGuest(): self
    {
        return static::create([
            'user_id' => null,
            'guest_token' => Str::random(40),
            'cart_status' => self::STATUS_IMMEDIATE,
        ]);
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
