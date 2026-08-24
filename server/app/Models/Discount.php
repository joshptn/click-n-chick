<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    public const TYPE_SENIOR = 'senior';

    public const TYPE_PWD = 'pwd';

    public static function types(): array
    {
        return [self::TYPE_SENIOR, self::TYPE_PWD];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const MINIMUM_PERCENTAGE = 20.00;

    public static function currentPercentage(): float
    {
        return max(
            self::MINIMUM_PERCENTAGE,
            Setting::number(Setting::DISCOUNT_PERCENTAGE, self::MINIMUM_PERCENTAGE)
        );
    }

    public const USAGE_TIMEZONE = 'Asia/Manila';

    protected $fillable = [
        'user_id',
        'discount_type',
        'id_image',
        'discount_status',
        'rejection_reason',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'vat_exempt' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isPending(): bool
    {
        return $this->discount_status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->discount_status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->discount_status === self::STATUS_REJECTED;
    }

    public static function activeFor(int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereIn('discount_status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->latest('id')
            ->first();
    }

    public function typeLabel(): string
    {
        return $this->discount_type === self::TYPE_PWD ? 'PWD' : 'Senior Citizen';
    }
}
