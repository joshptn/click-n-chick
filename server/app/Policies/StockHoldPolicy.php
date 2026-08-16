<?php

namespace App\Policies;

use App\Models\StockHold;
use App\Models\User;

class StockHoldPolicy
{
    private function isStaff(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, StockHold $hold): bool
    {
        return $this->isStaff($user);
    }

    public function release(User $user, StockHold $hold): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockHold $hold): bool
    {
        return false;
    }

    public function delete(User $user, StockHold $hold): bool
    {
        return false;
    }
}
