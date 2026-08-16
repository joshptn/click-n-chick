<?php

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;

class CartItemPolicy
{
    private function owns(User $user, CartItem $item): bool
    {
        if ($item->user_id !== null && (int) $item->user_id === (int) $user->id) {
            return true;
        }

        $cart = $item->cart;

        return $cart !== null
            && $cart->user_id !== null
            && (int) $cart->user_id === (int) $user->id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function delete(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }
}
