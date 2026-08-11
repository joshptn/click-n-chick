<?php

namespace App\Policies;

use App\Models\Food;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FoodPolicy
{
   public function isAdmin(User $user): bool
    {
        return $user->role == "admin";
    }

    
}
