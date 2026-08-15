<?php

namespace App\Policies;

use App\Models\LoyaltyTransaction;
use App\Models\User;

class LoyaltyTransactionPolicy
{
    private function owns(User $user, LoyaltyTransaction $transaction): bool
    {
        return $transaction->user_id !== null && (int) $transaction->user_id === (int) $user->id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function view(User $user, LoyaltyTransaction $transaction): bool
    {
        return $this->owns($user, $transaction)
            || $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function redeem(User $user): bool
    {
        return $user->hasRole(User::ROLE_CUSTOMER);
    }

    public function adjust(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LoyaltyTransaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, LoyaltyTransaction $transaction): bool
    {
        return false;
    }
}
