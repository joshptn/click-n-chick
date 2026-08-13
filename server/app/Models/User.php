<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
            'password' => 'hashed',
            // Left exactly as-is - see the note raised alongside this change.
            'phone_number' => 'encrypted',
            'loyalty_points' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Canonical PH mobile form: '+63' followed by the 10-digit subscriber
     * number. Accepts the shapes people actually type - 09XXXXXXXXX,
     * 9XXXXXXXXX, +639XXXXXXXXX - plus spaces, dashes and parentheses.
     *
     * Returns null when the input cannot be resolved to a PH mobile number,
     * which callers must treat as "no match" rather than "match anything".
     */
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

    /**
     * Deterministic blind index for phone_number.
     *
     * phone_number is cast to 'encrypted', and Laravel's encryption uses a
     * random IV, so the same number produces different ciphertext every time
     * and can never be matched in a WHERE clause. This keyed hash gives an
     * equality-searchable stand-in, stored in the unique phone_number_hash
     * column. It is keyed on APP_KEY, so rotating APP_KEY invalidates it -
     * the same constraint the encrypted column already carries.
     */
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

    public function notifications(){
        return $this->hasMany(Notification::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /** Carts owned by this user; guest carts have a null user_id. */
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

    /** Ledger of record; users.loyalty_points is the derived running balance. */
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
