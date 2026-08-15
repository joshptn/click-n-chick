<?php

namespace App\Policies;

use App\Models\User;

    
class UserPolicy
{
    public function isStoreManager(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function isStoreAgent(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function isStaff(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }
    public function isAdmin(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->isStoreManager($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->isStoreManager($user);
    }

    public function view(User $user, User $target): bool
    {
        return $user->id === $target->id || $this->isStoreManager($user);
    }

    public function update(User $user, User $target): bool
    {
        return $user->id === $target->id || $this->isStoreManager($user);
    }

    public function updateRole(User $user, User $target): bool
    {
        return $this->isStoreManager($user) && $user->id !== $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        return $this->isStoreManager($user) && $user->id !== $target->id;
    }
}
