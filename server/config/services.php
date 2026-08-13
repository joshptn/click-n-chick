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

    'philsms' => [
        // Master switch. Leave false until the dashboard approves a sender ID -
        // the provider rejects every send without one.
        'enabled' => env('PHILSMS_ENABLED', false),
        'endpoint' => env('PHILSMS_ENDPOINT', 'https://dashboard.philsms.com/api/v3/sms/send'),
        'token' => env('PHILSMS_API_TOKEN'),
        'sender_id' => env('PHILSMS_SENDER_ID', 'ClicknChick'),
    ],

];
