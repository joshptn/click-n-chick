<?php

namespace App\Services\Store;

use App\Models\User;

/**
 * Who a customer is told to contact (decided 2026-11-23): the phone number of
 * the first active Store Agent who has one on their profile, verified or not.
 * With none, messages simply say "the store".
 *
 * Read once per request; the number is stored encrypted, so it cannot be
 * filtered on in SQL beyond "has one".
 */
class StoreContact
{
    private bool $loaded = false;

    private ?string $phone = null;

    public function phone(): ?string
    {
        if (! $this->loaded) {
            $this->loaded = true;

            $agents = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('account_status', User::STATUS_ACTIVE)
                ->whereNotNull('phone_number')
                ->orderBy('id')
                ->get();

            foreach ($agents as $agent) {
                $number = trim((string) $agent->phone_number);

                if ($number !== '') {
                    $this->phone = $number;
                    break;
                }
            }
        }

        return $this->phone;
    }

    /** "the store at 09171234567", or "the store". */
    public function theStore(): string
    {
        $phone = $this->phone();

        return $phone === null ? 'the store' : "the store at {$phone}";
    }
}
