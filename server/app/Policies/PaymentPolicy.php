<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    private function owns(User $user, Payment $payment): bool
    {
        if ($payment->user_id !== null && (int) $payment->user_id === (int) $user->id) {
            return true;
        }

        $order = $payment->order;

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

    public function view(User $user, Payment $payment): bool
    {
        return $this->owns($user, $payment) || $this->isStaff($user);
    }

    public function verify(User $user, Payment $payment): bool
    {
        return $user->hasRole(User::ROLE_ADMIN);
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasRole(User::ROLE_SUPER_ADMIN);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
