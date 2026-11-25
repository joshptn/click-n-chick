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
        'timeout' => (int) env('RECAPTCHA_TIMEOUT', 5),
    ],

    'semaphore' => [
        'endpoint' => env('SEMAPHORE_ENDPOINT', 'https://api.semaphore.co/api/v4/otp'),
        'key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
    ],

    'gmail' => [
        'client_id' => env('GMAIL_CLIENT_ID'),
        'client_secret' => env('GMAIL_CLIENT_SECRET'),
        'refresh_token' => env('GMAIL_REFRESH_TOKEN'),
        'endpoint' => env('GMAIL_ENDPOINT', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'),
        'timeout' => (int) env('GMAIL_TIMEOUT', 10),
    ],

    /*
     * Push through Firebase Cloud Messaging (HTTP v1). FIREBASE_CREDENTIALS is
     * the service account key: its JSON, or that JSON base64-encoded (easier to
     * paste into Render). A secret: never commit it. Unset, pushes are skipped
     * and the in-app notifications still go out.
     */
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'timeout' => (int) env('FIREBASE_TIMEOUT', 10),
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

    /*
     * Driving distance for the delivery fee. OpenRouteService for now, on its
     * new host. OSRM on our own server (docs/OSRM-Setup.md) is built and is
     * switched on with ROUTING_DRIVER=osrm once that server exists: it routes
     * into subdivisions whose roads OpenStreetMap tags as private, which ORS
     * refuses (decided 2026-11-26; server set-up postponed the same day).
     *
     * OSRM_ENDPOINT is the server's https address, and OSRM_TOKEN must match
     * OSRM_TOKEN in that server's osrm/.env. Both are secrets in production.
     */
    'routing' => [
        'driver' => env('ROUTING_DRIVER', 'openrouteservice'),

        'osrm' => [
            'endpoint' => env('OSRM_ENDPOINT'),
            'token' => env('OSRM_TOKEN'),
            'profile' => env('OSRM_PROFILE', 'driving'),
        ],

        'openrouteservice' => [
            // The old api.openrouteservice.org host was shut down in November 2026 (it answers 410).
            'endpoint' => env('ORS_ENDPOINT', 'https://api.heigit.org/openrouteservice'),
            'api_key' => env('ORS_API_KEY'),
            'profile' => env('ORS_PROFILE', 'driving-car'),
        ],

        // A pin farther than this from any road has no route (OSRM; ORS fixes it at 350).
        'snap_metres' => (int) env('ROUTING_SNAP_METRES', 350),

        'timeout' => (int) env('ROUTING_TIMEOUT', 6),
        'connect_timeout' => (int) env('ROUTING_CONNECT_TIMEOUT', 3),
        'cache_ttl' => (int) env('ROUTING_CACHE_TTL', 604800),

        'lock_wait' => (int) env('ROUTING_LOCK_WAIT', 3),
    ],

    /*
     * Xendit (FR-04.2, decided 2026-11-17): QR Ph and GCash through its hosted
     * checkout (Payment Sessions). Both values are secrets. A test-mode key
     * starts xnd_development_; the webhook token is the "verification token" on
     * Xendit's Webhooks settings page and arrives as the x-callback-token header.
     *
     * return_url is where Xendit sends the customer back. Xendit accepts only
     * HTTPS there, so the web app's /payment/return page on the deployed site.
     */
    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'endpoint' => env('XENDIT_ENDPOINT', 'https://api.xendit.co'),
        'return_url' => env(
            'XENDIT_RETURN_URL',
            rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/payment/return'
        ),
        'timeout' => (int) env('XENDIT_TIMEOUT', 15),
    ],

    'maxmind' => [
        'account_id' => env('MAXMIND_ACCOUNT_ID'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
        'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
        'endpoint' => env('MAXMIND_ENDPOINT', 'https://download.maxmind.com/geoip/databases'),
        'database' => env('MAXMIND_DATABASE', storage_path('app/geoip/GeoLite2-City.mmdb')),
        'max_age_days' => (int) env('MAXMIND_MAX_AGE_DAYS', 7),
        'timeout' => (int) env('MAXMIND_TIMEOUT', 120),
    ],

];
