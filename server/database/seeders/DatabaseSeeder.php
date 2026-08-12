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
            ['email' => 'superadmin@chicknclick.test', 'name' => 'Super Admin',   'role' => 'super_admin'],
            ['email' => 'admin@chicknclick.test',      'name' => 'Store Agent',   'role' => 'admin'],
            ['email' => 'customer@chicknclick.test',   'name' => 'Test Customer', 'role' => 'customer'],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name'     => $account['name'],
                    'role'     => $account['role'],
                    'password' => Hash::make('password123'),
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
