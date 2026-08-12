<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function isSuperAdmin(User $user): bool
    {
        return $user->role === 'super_admin';
    }

    public function isAdmin(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }
}
