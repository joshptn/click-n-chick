<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderItemPolicy
{
     public function isAdmin(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->role === 'super_admin';
    }
}
