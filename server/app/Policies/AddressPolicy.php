<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

class AddressPolicy
{
    private function owns(User $user, Address $address): bool
    {
        return $address->user_id !== null && (int) $address->user_id === (int) $user->id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }


    public function view(User $user, Address $address): bool
    {
        return $this->owns($user, $address);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Address $address): bool
    {
        return $this->owns($user, $address);
    }

    public function delete(User $user, Address $address): bool
    {
        return $this->owns($user, $address);
    }

    public function setDefault(User $user, Address $address): bool
    {
        return $this->owns($user, $address);
    }
}
