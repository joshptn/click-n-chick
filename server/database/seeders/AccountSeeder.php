<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Verification\Channel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One Store Manager, one Store Agent and one customer.
 *
 * Who they are is here; how to sign in as them is in .env (config/seeding.php),
 * because this file is public. Re-running resets their passwords to
 * SEED_ACCOUNT_PASSWORD.
 */
class AccountSeeder extends Seeder
{
    private const ACCOUNTS = [
        'super_admin' => ['first_name' => 'Super', 'last_name' => 'Admin', 'role' => User::ROLE_SUPER_ADMIN],
        'admin' => ['first_name' => 'Store', 'last_name' => 'Agent', 'role' => User::ROLE_ADMIN],
        'customer' => ['first_name' => 'Test', 'last_name' => 'Customer', 'role' => User::ROLE_CUSTOMER],
    ];

    public function run(): void
    {
        $password = config('seeding.password');

        if (blank($password)) {
            $this->command?->warn('SEED_ACCOUNT_PASSWORD is not set, so no accounts were created.');

            return;
        }

        foreach (self::ACCOUNTS as $key => $account) {
            $email = config("seeding.accounts.{$key}.email");
            $phone = config("seeding.accounts.{$key}.phone");

            if (blank($email) || blank($phone)) {
                $this->command?->warn('The '.str_replace('_', ' ', $key).' account was skipped: its SEED_ email or phone is not set.');

                continue;
            }

            User::updateOrCreate(
                ['email' => $email],
                [
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    'role' => $account['role'],
                    'password' => Hash::make($password),
                    'phone_number' => $phone,
                    'phone_number_hash' => User::hashPhoneNumber($phone),
                    'verification_channel' => Channel::Email->value,
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                    'account_status' => User::STATUS_ACTIVE,
                ]
            );
        }
    }
}
