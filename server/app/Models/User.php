<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Verification\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const PENDING_VERIFICATION_HOURS = 12;

    public const STATUS_ACTIVE = 'active';

    public function isPendingVerification(): bool
    {
        return $this->account_status === self::STATUS_PENDING_VERIFICATION;
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
