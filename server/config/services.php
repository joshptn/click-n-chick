<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        // 'log' writes the OTP to the log instead of calling the provider.
        // Default everywhere; only a deliberate manual test flips it.
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'semaphore' => [
        'endpoint' => env('SEMAPHORE_ENDPOINT', 'https://api.semaphore.co/api/v4/otp'),
        'key' => env('SEMAPHORE_API_KEY'),
        // Empty -> omit `sendername` and let the account default apply.
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
    ],

];
