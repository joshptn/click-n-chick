<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;


class OrderItemPolicy
{
    private function ownsParentOrder(User $user, OrderItem $item): bool
    {
        $order = $item->order;

        return $order !== null
            && $order->user_id !== null
            && (int) $order->user_id === (int) $user->id;
    }

    private function isStaff(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, OrderItem $item): bool
    {
        return $this->ownsParentOrder($user, $item) || $this->isStaff($user);
    }

    public function update(User $user, OrderItem $item): bool
    {
        return false;
    }

    public function delete(User $user, OrderItem $item): bool
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
