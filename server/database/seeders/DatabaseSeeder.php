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
        // Create admin account
        User::updateOrCreate([
            'name' => 'Admin User',
            'email' => 'admin@gmail.com',
            'role' => 'admin',
            'password' => Hash::make('password123'), // make sure to hash the password
        ]);

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
