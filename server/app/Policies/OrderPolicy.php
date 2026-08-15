<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;


class OrderPolicy
{
    private const STATUS_PENDING = 'pending';

    private function owns(User $user, Order $order): bool
    {
        return $order->user_id !== null && (int) $order->user_id === (int) $user->id;
    }

    private function isStaff(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }


    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order) || $this->isStaff($user);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->isStaff($user);
    }

    public function cancel(User $user, Order $order): bool
    {
        if ($this->owns($user, $order)) {
            return $order->status === self::STATUS_PENDING;
        }

        return $this->isStaff($user);
    }

    public function confirmReceipt(User $user, Order $order): bool
    {
        return $this->owns($user, $order)
            || $user->hasRole(User::ROLE_ADMIN);
    }


    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function isAdmin(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }
}
