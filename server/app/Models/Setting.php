<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One row of Store-Manager-governed configuration.
 *
 * Read through the static accessors rather than the query builder: they cache,
 * and settings are read on request paths that run far more often than they are
 * written. Every write invalidates its own key, so a change is visible
 * immediately rather than after a TTL - which matters for BR-27, where a rate
 * change is supposed to float to carts already open.
 */
class Setting extends Model
{
    use HasFactory;

    /** The statutory discount rate, in percent (BR-10, BR-27, BR-34). */
    public const DISCOUNT_PERCENTAGE = 'discount.percentage';

    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Keep the cache honest however a row is written.
     *
     * put() invalidates its own key, but a row can also be created or changed
     * through plain Eloquent - a seeder, a console command, a future admin
     * screen. Because the cache never expires, a write that skipped put() would
     * be invisible until something else happened to clear it. Hooking the model
     * means the invalidation cannot be forgotten at the call site.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget(self::cacheKey($setting->key)));
        static::deleted(fn (self $setting) => Cache::forget(self::cacheKey($setting->key)));
    }

    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    /**
     * The raw stored value, or $default when the key has never been set.
     *
     * Cached forever and invalidated on write - a setting that is read on
     * every profile load should not be a database round trip each time.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::rememberForever(
            self::cacheKey($key),
            // Sentinel, because a genuine null and "no row" must be
            // distinguishable - caching null would re-query forever.
            fn () => static::query()->where('key', $key)->value('value') ?? '__unset__'
        );

        return $value === '__unset__' ? $default : $value;
    }

    /** Numeric read. Non-numeric stored text falls back to $default. */
    public static function number(string $key, float $default): float
    {
        $raw = static::get($key);

        return is_numeric($raw) ? (float) $raw : $default;
    }

    public static function put(string $key, string|int|float $value, ?int $updatedBy = null): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'updated_by' => $updatedBy]
        );

        Cache::forget(self::cacheKey($key));
    }

    /** Drop a cached value without writing - used by tests and by seeding. */
    public static function forget(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }
}
