<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
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
            ['email' => 'superadmin@chicknclick.test', 'first_name' => 'Super', 'last_name' => 'Admin',    'role' => 'super_admin'],
            ['email' => 'admin@chicknclick.test',      'first_name' => 'Store', 'last_name' => 'Agent',    'role' => 'admin'],
            ['email' => 'customer@chicknclick.test',   'first_name' => 'Test',  'last_name' => 'Customer', 'role' => 'customer'],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'first_name' => $account['first_name'],
                    'last_name'  => $account['last_name'],
                    'role'       => $account['role'],
                    'password'   => Hash::make('Password123!'),
                ]
            );
        }

        Category::updateOrCreate([
            'name'=>'Drinks'
        ]);
        Category::updateOrCreate([
            'name'=>'Sides'
        ]);
        Category::updateOrCreate([
            'name'=>'Addons'
        ]);

    }
}
