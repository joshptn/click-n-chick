<?php

/*
| The accounts DatabaseSeeder creates, read from .env only.
|
| Nothing here has a default on purpose: the repository is public, so an
| address, phone number or password written into the seeder would be a working
| login for anyone who reads it. An account with any value missing is skipped.
*/

return [
    'password' => env('SEED_ACCOUNT_PASSWORD'),

    'accounts' => [
        'super_admin' => [
            'email' => env('SEED_SUPER_ADMIN_EMAIL'),
            'phone' => env('SEED_SUPER_ADMIN_PHONE'),
        ],
        'admin' => [
            'email' => env('SEED_ADMIN_EMAIL'),
            'phone' => env('SEED_ADMIN_PHONE'),
        ],
        'customer' => [
            'email' => env('SEED_CUSTOMER_EMAIL'),
            'phone' => env('SEED_CUSTOMER_PHONE'),
        ],
    ],
];
