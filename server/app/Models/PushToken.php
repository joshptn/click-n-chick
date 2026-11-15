<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a push for an account can be delivered: one FCM registration from the
 * installed PWA or the native app, tied to the sign-in that registered it and
 * deleted with it (see the migration).
 */
class PushToken extends Model
{
    public const PLATFORM_WEB = 'web';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORMS = [self::PLATFORM_WEB, self::PLATFORM_ANDROID];

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'token',
        'platform',
    ];

    /** The token is a delivery address for this account; it is never sent back out. */
    protected $hidden = [
        'token',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Only customer accounts receive pushes (customer module only). Staff cannot
     * register a token, and this keeps an account whose role later changed from
     * still being reached on an old one.
     */
    public function scopeForCustomers(Builder $query): Builder
    {
        return $query->whereHas('user', fn (Builder $user) => $user->where('role', User::ROLE_CUSTOMER));
    }
}
