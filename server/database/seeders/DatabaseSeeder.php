<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Verification\Channel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $accounts = [
            ['email' => 'superadmin@chicknclick.test', 'first_name' => 'Super', 'last_name' => 'Admin',    'role' => 'super_admin', 'phone' => '+639170000001'],
            ['email' => 'admin@chicknclick.test',      'first_name' => 'Store', 'last_name' => 'Agent',    'role' => 'admin',       'phone' => '+639170000002'],
            ['email' => 'customer@chicknclick.test',   'first_name' => 'Test',  'last_name' => 'Customer', 'role' => 'customer',    'phone' => '+639170000003'],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'first_name' => $account['first_name'],
                    'last_name'  => $account['last_name'],
                    'role'       => $account['role'],
                    'password'   => Hash::make('Password123!'),
                    'phone_number' => $account['phone'],
                    'phone_number_hash' => User::hashPhoneNumber($account['phone']),
                    // Login gates on the per-channel timestamp, not on
                    // account_status. Without these three a seeded account is
                    // created already locked out of its own sign-in.
                    'verification_channel' => Channel::Email->value,
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                    'account_status' => User::STATUS_ACTIVE,
                ]
            );
        }

        // Order matters: foods resolve their category and add-ons by name.
        $this->call([
            CategorySeeder::class,
            AddonSeeder::class,
            FoodSeeder::class,
            PosterSeeder::class,
        ]);
    }
}
