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
        'name',
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
