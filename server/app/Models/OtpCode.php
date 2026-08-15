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
