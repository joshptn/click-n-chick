<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    public const DISCOUNT_PERCENTAGE = 'discount.percentage';

    public const DELIVERY_BASE_KM = 'delivery.base_km';

    public const DELIVERY_BASE_FEE = 'delivery.base_fee';

    public const DELIVERY_EXTRA_FEE_PER_KM = 'delivery.extra_fee_per_km';

    public const LOYALTY_POINTS_PER_PESO = 'loyalty.points_per_peso';

    public const LOYALTY_PESO_PER_POINT = 'loyalty.peso_per_point';

    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget(self::cacheKey($setting->key)));
        static::deleted(fn (self $setting) => Cache::forget(self::cacheKey($setting->key)));
    }

    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::rememberForever(
            self::cacheKey($key),
            fn () => static::query()->where('key', $key)->value('value') ?? '__unset__'
        );

        return $value === '__unset__' ? $default : $value;
    }

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

    public static function forget(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }
}
