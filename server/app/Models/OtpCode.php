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

    /** otp_codes.purpose for the blocking registration flow. */
    public const PURPOSE_REGISTRATION = 'registration';

    /** Confirming the channel a user is turning 2FA on for. */
    public const PURPOSE_TWO_FACTOR_ENABLE = 'two_factor_enable';

    /** The per-login challenge once 2FA is on. */
    public const PURPOSE_TWO_FACTOR_LOGIN = 'two_factor_login';

    /** BR-33: re-verifying identity before a password change is accepted. */
    public const PURPOSE_PASSWORD_CHANGE = 'password_change';

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

    /** Null when the code precedes account creation. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
