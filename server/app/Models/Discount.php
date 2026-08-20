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
     * The statutory floor, in percent.
     *
     * RA 9994 (senior citizens) and RA 10754 (PWD) both set 20%. The Store
     * Manager may raise the configured rate but never lower it past this
     * (BR-34), so this is a floor, not the rate itself.
     */
    public const MINIMUM_PERCENTAGE = 20.00;

    /**
     * The rate in force right now, in percent.
     *
     * Read live on every use rather than copied onto the claim, so a rate
     * change reaches everyone at once - including customers approved long
     * before it (BR-27).
     *
     * Clamped to the statutory floor on READ as well as on write. The write
     * path validates, but a value can also arrive by seed, migration, or a
     * direct database edit, and none of those go through validation. Clamping
     * here means no code path can compute a discount below the legal rate.
     */
    public static function currentPercentage(): float
    {
        return max(
            self::MINIMUM_PERCENTAGE,
            Setting::number(Setting::DISCOUNT_PERCENTAGE, self::MINIMUM_PERCENTAGE)
        );
    }

    /**
     * BR-09 / FR-05.3, recorded here for the checkout work that will enforce it.
     *
     * The benefit is once per calendar day in Asia/Manila, and a cancelled or
     * refunded order STILL consumes that day (confirmed decision). So the check
     * is "does this account have any order dated today carrying a discount",
     * regardless of that order's final status - not "any *completed* order".
     * Deriving it that way needs no extra column: orders.discount_amount and
     * orders.created_at are enough.
     */
    public const USAGE_TIMEZONE = 'Asia/Manila';

    protected $fillable = [
        'user_id',
        'discount_type',
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
