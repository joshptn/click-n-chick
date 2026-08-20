<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'channel',
        'identifier_hash',
        'code_hash',
        'purpose',
        'ip_address',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    public const PURPOSE_REGISTRATION = 'registration';

    public const PURPOSE_TWO_FACTOR_ENABLE = 'two_factor_enable';

    public const PURPOSE_TWO_FACTOR_LOGIN = 'two_factor_login';

    public const PURPOSE_PASSWORD_CHANGE = 'password_change';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    /**
     * Identity step-up after a low reCAPTCHA score at login (FR-01.15, BR-35).
     *
     * Deliberately not PURPOSE_TWO_FACTOR_LOGIN. They are issued for different
     * reasons and redeemed through different gates, and sharing a purpose would
     * let a code minted for one be spent on the other.
     */
    public const PURPOSE_STEP_UP = 'step_up';

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
