<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Exact origins (scheme + host + port) permitted to call the paths above.
    | Driven by ALLOWED_ORIGINS in .env as a comma-separated list; entries are
    | trimmed and blanks dropped so a trailing comma cannot admit an empty origin.
    | Exact-match only by design - a tunnel host must be named in full here rather
    | than allowed via a wildcard such as *.ngrok-free.dev.
    */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env(
            'ALLOWED_ORIGINS',
            'http://localhost:5173,http://127.0.0.1:5173'
        ))),
        static fn ($origin) => $origin !== ''
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];