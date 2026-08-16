<?php

namespace App\Policies;

use App\Models\Poster;
use App\Models\User;

class PosterPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Poster $poster): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function update(User $user, Poster $poster): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function delete(User $user, Poster $poster): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }
}
