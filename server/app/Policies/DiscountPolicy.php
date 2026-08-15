<?php

namespace App\Policies;

use App\Models\Discount;
use App\Models\User;

class DiscountPolicy
{
    private function owns(User $user, Discount $discount): bool
    {
        return $discount->user_id !== null && (int) $discount->user_id === (int) $user->id;
    }

    private function isStaff(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, Discount $discount): bool
    {
        return $this->owns($user, $discount) || $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_CUSTOMER);
    }

    public function approve(User $user, Discount $discount): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function reject(User $user, Discount $discount): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function update(User $user, Discount $discount): bool
    {
        return false;
    }

    public function delete(User $user, Discount $discount): bool
    {
        return false;
    }
}
