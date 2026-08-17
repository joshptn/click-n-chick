<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One browser/app install that has signed in to an account (FR-01.13).
 *
 * The user-facing concept is the device; the security object is the Sanctum
 * token. A device may hold several tokens over its life - each login mints a
 * new one - so revoking a device means revoking every token pointing at it,
 * not just the newest.
 */
class KnownDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_fingerprint',
        'device_name',
        'platform',
        'last_ip_address',
        'is_trusted',
        'last_seen_at',
    ];

    /**
     * Never serialise the fingerprint. It is not a credential, but it is the
     * only stable per-device value in the row and there is no reason for a
     * client to hold one.
     */
    protected $hidden = [
        'device_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'is_trusted' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The authentication sessions issued to this device. */
    public function tokens()
    {
        return $this->hasMany(PersonalAccessToken::class, 'known_device_id');
    }

    /**
     * Revoke every session this device holds.
     *
     * Deliberately scoped to this device's id only - the whole point of the
     * feature is that the user's other devices stay signed in.
     *
     * @return int tokens deleted
     */
    public function revokeSessions(): int
    {
        return $this->tokens()->delete();
    }
}
