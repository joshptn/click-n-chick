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
