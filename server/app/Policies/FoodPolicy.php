<?php

namespace App\Policies;

use App\Models\Food;
use App\Models\User;


class FoodPolicy
{

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Food $food): bool
    {
        return true;
    }


    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function update(User $user, Food $food): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function delete(User $user, Food $food): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }
    public function manageStock(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }
    public function manageAvailability(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function isAdmin(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }
}
