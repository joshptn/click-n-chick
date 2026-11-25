<?php

namespace App\Models;

use App\Services\Store\StoreAvailability;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A homepage poster: an image, cropped to the banner's 3:1 shape when it is
 * uploaded. It is not clickable, carries no discount logic and has no link to
 * orders.
 */
class Poster extends Model
{
    use HasFactory;

    /** The banner's shape (width : height), on the web and in the app. */
    public const RATIO = 3;

    /** The smallest crop accepted: the banner's widest size on the web. */
    public const MIN_WIDTH = 1200;

    public const MIN_HEIGHT = 400;

    /** The largest file accepted, in kilobytes (2 MB). */
    public const MAX_KB = 2048;

    protected $fillable = [
        'image',
        'is_active',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            // The last day it shows on the homepage, in the store's timezone.
            'expires_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Posters the homepage shows right now, oldest first.
     *
     * Expiry includes the day itself: a poster set to expire today still shows
     * today and is gone tomorrow. "Today" is the store's date, not UTC's.
     */
    public function scopeVisible($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', self::storeToday());
            })
            ->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->toDateString() < self::storeToday();
    }

    public static function storeToday(): string
    {
        return app(StoreAvailability::class)->now()->toDateString();
    }
}
