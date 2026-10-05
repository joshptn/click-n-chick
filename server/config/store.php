<?php

return [

    'timezone' => env('STORE_TIMEZONE', 'Asia/Manila'),

    'origin' => [
        'latitude' => (float) env('STORE_ORIGIN_LAT', 14.958753194320153),
        'longitude' => (float) env('STORE_ORIGIN_LNG', 120.75846924744896),
    ],

    'max_driving_km' => (float) env('STORE_MAX_DRIVING_KM', 45.0),

    'hours' => [
        'opens_at' => env('STORE_OPENS_AT', '01:00'),
        'closes_at' => env('STORE_CLOSES_AT', '20:00'),
    ],

    'pickup' => [
        'lead_minutes' => (int) env('STORE_PICKUP_LEAD_MINUTES', 20),
        'last_order_buffer_minutes' => (int) env('STORE_PICKUP_BUFFER_MINUTES', 15),
    ],

    'queue' => [
        'label_prefix' => env('STORE_QUEUE_LABEL_PREFIX', 'CNC'),
    ],

    'guest' => [
        'quote_per_minute' => (int) env('GUEST_QUOTE_PER_MINUTE', 40),
        'quote_per_day' => (int) env('GUEST_QUOTE_PER_DAY', 800),
        'geocode_per_minute' => (int) env('GUEST_GEOCODE_PER_MINUTE', 10),
        'geocode_per_day' => (int) env('GUEST_GEOCODE_PER_DAY', 500),
        'track_days_after_close' => (int) env('GUEST_TRACK_DAYS_AFTER_CLOSE', 7),
        'track_days_ceiling' => (int) env('GUEST_TRACK_DAYS_CEILING', 30),
        'cart_idle_days' => (int) env('GUEST_CART_IDLE_DAYS', 7),
    ],

];
