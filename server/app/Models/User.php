<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Verification\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'role',
        'account_status',
        'first_name',
        'last_name',
        'phone_number',
        'phone_number_hash',
        'verification_channel',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'phone_number_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
            'password' => 'hashed',
            // Left exactly as-is - see the note raised alongside this change.
            'phone_number' => 'encrypted',
            'loyalty_points' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
        ];
    }

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_CUSTOMER = 'customer';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_ADMIN,
        self::ROLE_CUSTOMER,
    ];

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** The one row orders point at once the staff member who cancelled them is deleted. */
    public const DELETED_STAFF_EMAIL = 'deleted-staff@system.invalid';

    /** The channel timestamp login gates on, in SQL (hasVerifiedChosenChannel). */
    private const CHOSEN_CHANNEL_VERIFIED_AT = "(CASE WHEN verification_channel = 'email' THEN email_verified_at ELSE phone_verified_at END)";

    public function isSuspended(): bool
    {
        return $this->account_status === self::STATUS_SUSPENDED;
    }

    /** Everyone but the deleted-staff placeholder, which is not an account. */
    public function scopeRealAccounts(Builder $query): Builder
    {
        return $query->where('email', '!=', self::DELETED_STAFF_EMAIL);
    }

    /** So the placeholder cannot be opened, changed or deleted through a route. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->realAccounts()->first();
    }

    /**
     * Keeps a store cancellation counting as the store's after the staff member
     * is deleted, without naming them. It cannot sign in: suspended, and a
     * password nobody has.
     */
    public static function deletedStaff(): self
    {
        $existing = static::query()->where('email', self::DELETED_STAFF_EMAIL)->first();

        if ($existing !== null) {
            return $existing;
        }

        $placeholder = new static;
        $placeholder->first_name = 'Deleted';
        $placeholder->last_name = 'staff';
        $placeholder->email = self::DELETED_STAFF_EMAIL;
        $placeholder->password = Str::random(64);
        $placeholder->role = self::ROLE_ADMIN;
        $placeholder->account_status = self::STATUS_SUSPENDED;
        $placeholder->save();

        return $placeholder;
    }

    public function scopeSignInReady(Builder $query): Builder
    {
        return $query->where('account_status', self::STATUS_ACTIVE)
            ->whereRaw(self::CHOSEN_CHANNEL_VERIFIED_AT.' IS NOT NULL');
    }

    /**
     * Accounts a Store Manager created that have not yet verified at first
     * sign-in. Self-registered accounts never appear here: they are created
     * only once their identifier is verified.
     */
    public function scopeAwaitingVerification(Builder $query): Builder
    {
        return $query
            ->where('account_status', self::STATUS_ACTIVE)
            ->whereRaw(self::CHOSEN_CHANNEL_VERIFIED_AT.' IS NULL');
    }

    public function hasVerifiedChannel(Channel|string|null $channel): bool
    {
        $value = $channel instanceof Channel ? $channel->value : $channel;

        return $value === Channel::Email->value
            ? $this->email_verified_at !== null
            : $this->phone_verified_at !== null;
    }

    public function hasVerifiedChosenChannel(): bool
    {
        return $this->hasVerifiedChannel($this->verification_channel);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return (bool) $this->two_factor_enabled
            && $this->two_factor_channel !== null;
    }

    public function hasGivenPrivacyConsent(): bool
    {
        return $this->privacy_consent_at !== null;
    }

    /**
     * Lower-cased and trimmed, so one address is one account however it is
     * typed. Anything that is not an address is null, never a wildcard.
     */
    public static function normalizeEmail(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = mb_strtolower(trim($raw));

        return filter_var($trimmed, FILTER_VALIDATE_EMAIL) ? $trimmed : null;
    }

    /** Every write goes through the same normalisation as every lookup. */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : (self::normalizeEmail($value) ?? mb_strtolower(trim($value))),
        );
    }

    public static function normalizePhoneNumber(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $subscriber = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $subscriber = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $subscriber = $digits;
        } else {
            return null;
        }

        return '+63'.$subscriber;
    }

    public static function hashPhoneNumber(?string $raw): ?string
    {
        $canonical = static::normalizePhoneNumber($raw);

        if ($canonical === null) {
            return null;
        }

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }

    public function Cart()
    {
        return $this->hasMany(CartItem::class);
    }

    public function Orders()
    {
        return $this->hasMany(Order::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function discounts()
    {
        return $this->hasMany(Discount::class);
    }

    /**
     * The claim that represents this account's current standing.
     *
     * Latest by id, so a customer who was rejected and applied again is judged
     * on the new attempt rather than the old refusal.
     */
    public function latestDiscountClaim()
    {
        return $this->hasOne(Discount::class)->latestOfMany();
    }

    /** The approved claim behind an entitlement, if there is one. */
    public function approvedDiscount()
    {
        return $this->hasOne(Discount::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('discount_status', Discount::STATUS_APPROVED)
        );
    }

    /**
     * Entitled to a statutory discount right now.
     *
     * Derived from the claim rather than mirrored onto a users column: a
     * denormalised flag is one more thing that can disagree with the row an
     * agent actually approved, and this is read on profile load, not per item.
     */
    public function isDiscountEligible(): bool
    {
        return $this->discounts()
            ->where('discount_status', Discount::STATUS_APPROVED)
            ->exists();
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function otpCodes()
    {
        return $this->hasMany(OtpCode::class);
    }

    public function authEvents()
    {
        return $this->hasMany(AuthEvent::class);
    }

    public function knownDevices()
    {
        return $this->hasMany(KnownDevice::class);
    }

    public function posters()
    {
        return $this->hasMany(Poster::class, 'created_by');
    }
}
