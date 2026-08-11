<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderItemPolicy
{
    public function isAdmin(User $user): bool
    {
        return $user->role == "admin";
    }
}
