<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthEvent extends Model
{
    use HasFactory;

    /**
     * event_type written when the account holder turns 2FA on.
     *
     * The device-related types live on DeviceRegistrar, next to the code that
     * raises them; these two have no device context, so they live here.
     */
    public const TWO_FACTOR_ENABLED = 'two_factor_enabled';

    /**
     * event_type written when the account holder turns 2FA off.
     *
     * Worth recording precisely because it is a downgrade: if an account is
     * later compromised, this row is how you find out when the second factor
     * stopped protecting it, and from which IP.
     */
    public const TWO_FACTOR_DISABLED = 'two_factor_disabled';

    protected $fillable = [
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
    ];

    /** Null when a failed login carried an unrecognised identifier. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
