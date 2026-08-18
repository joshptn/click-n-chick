<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer's claim to a statutory discount (senior citizen / PWD).
 *
 * Account-level entitlement, not an order line. See the migration for why the
 * two were separated. A customer may hold many rows over time - a rejection is
 * recoverable, so re-applying appends rather than overwrites - but only ever
 * one that is pending or approved. `Discount::activeFor()` is what enforces it.
 */
class Discount extends Model
{
    use HasFactory;

    public const TYPE_SENIOR = 'senior';

    public const TYPE_PWD = 'pwd';

    /** @return array<int, string> */
    public static function types(): array
    {
        return [self::TYPE_SENIOR, self::TYPE_PWD];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * The statutory rate, in percent.
     *
     * RA 9994 (senior citizens) and RA 10754 (PWD) both set 20%. Written into
     * the row at claim time so a future rate change does not silently restate
     * what an already-approved customer was granted.
     */
    public const STATUTORY_PERCENTAGE = 20.00;

    protected $fillable = [
        'user_id',
        'discount_type',
        'discount_percentage',
        'vat_exempt',
        'id_image',
        'discount_status',
        'rejection_reason',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
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

    /**
     * The claim that blocks a new application, if any.
     *
     * Pending (already waiting on an agent) and approved (already entitled)
     * both block; rejected does not, which is what makes a second attempt
     * possible after a blurry photo.
     */
    public static function activeFor(int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereIn('discount_status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->latest('id')
            ->first();
    }

    /** Human label, used in notifications and in the UI. */
    public function typeLabel(): string
    {
        return $this->discount_type === self::TYPE_PWD ? 'PWD' : 'Senior Citizen';
    }
}
