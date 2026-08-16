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

    'recaptcha' => [
        // On by default but inert until both keys exist, so pasting the
        // credentials in is the only step needed to turn it on.
        'enabled' => env('RECAPTCHA_ENABLED', true),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        // Required. Without it the verify call has no URL to post to, the
        // service treats that as an outage and fails open - which silently
        // disables reCAPTCHA on every guarded route.
        'verify_url' => env('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify'),
        // v3 returns 0.0-1.0; below this the request is treated as automated.
        'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
        'timeout' => (int) env('RECAPTCHA_TIMEOUT', 5),
    ],

    'semaphore' => [
        'endpoint' => env('SEMAPHORE_ENDPOINT', 'https://api.semaphore.co/api/v4/otp'),
        'key' => env('SEMAPHORE_API_KEY'),
        // Empty -> omit `sendername` and let the account default apply.
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
    ],

];
