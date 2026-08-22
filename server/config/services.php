<?php

return [


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
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'recaptcha' => [
        'enabled' => env('RECAPTCHA_ENABLED', true),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'verify_url' => env('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify'),
        'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
        'timeout' => (int) env('RECAPTCHA_TIMEOUT', 5),
    ],

    'semaphore' => [
        'endpoint' => env('SEMAPHORE_ENDPOINT', 'https://api.semaphore.co/api/v4/otp'),
        'key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
    ],

    'nominatim' => [
        'endpoint' => env('NOMINATIM_ENDPOINT', 'https://nominatim.openstreetmap.org'),

        'user_agent' => env(
            'NOMINATIM_USER_AGENT',
            'ClickNChick/1.0 (BES House of Chicken, Apalit; +'.env('APP_URL', 'http://localhost').')'
        ),

        'min_interval_ms' => (int) env('NOMINATIM_MIN_INTERVAL_MS', 1100),
        'gate_timeout_ms' => (int) env('NOMINATIM_GATE_TIMEOUT_MS', 3000),

        'timeout' => (int) env('NOMINATIM_TIMEOUT', 6),
        'connect_timeout' => (int) env('NOMINATIM_CONNECT_TIMEOUT', 3),

        'search_ttl' => (int) env('NOMINATIM_SEARCH_TTL', 86400),
        'reverse_ttl' => (int) env('NOMINATIM_REVERSE_TTL', 604800),
    ],

    'session_security' => [
        'revoke_on_device_mismatch' => (bool) env('SESSION_REVOKE_ON_DEVICE_MISMATCH', false),
    ],

];
