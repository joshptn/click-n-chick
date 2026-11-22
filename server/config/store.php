<?php

return [

    'timezone' => env('STORE_TIMEZONE', 'Asia/Manila'),

    'origin' => [
        'latitude' => (float) env('STORE_ORIGIN_LAT', 14.958753194320153),
        'longitude' => (float) env('STORE_ORIGIN_LNG', 120.75846924744896),
    ],

    'max_driving_km' => (float) env('STORE_MAX_DRIVING_KM', 45.0),

    'hours' => [
        'opens_at' => env('STORE_OPENS_AT', '07:00'),
        'closes_at' => env('STORE_CLOSES_AT', '20:00'),
    ],

    // Decided 2026-11-23: a customer may cancel a placed immediate order only
    // once the store has had this long, from payment, to confirm it (BR-19).
    'cancel_wait_minutes' => (int) env('STORE_CANCEL_WAIT_MINUTES', 15),

    'pickup' => [
        'lead_minutes' => (int) env('STORE_PICKUP_LEAD_MINUTES', 20),
        'last_order_buffer_minutes' => (int) env('STORE_PICKUP_BUFFER_MINUTES', 15),
    ],

    'guest' => [
        'quote_per_minute' => (int) env('GUEST_QUOTE_PER_MINUTE', 40),
        'quote_per_day' => (int) env('GUEST_QUOTE_PER_DAY', 800),
        'geocode_per_minute' => (int) env('GUEST_GEOCODE_PER_MINUTE', 10),
        'geocode_per_day' => (int) env('GUEST_GEOCODE_PER_DAY', 500),
        // A tracking link works this many days from placement, whatever
        // happens to the order meanwhile (decided 2026-11-22).
        'track_days' => (int) env('GUEST_TRACK_DAYS', 7),
        // Resending the link from the tracking page: one send per wait, the
        // automatic email included, and a cap per order (decided 2026-11-22).
        'link_resend_wait_seconds' => (int) env('GUEST_LINK_RESEND_WAIT', 30),
        'link_resends_per_order' => (int) env('GUEST_LINK_RESENDS', 10),
        'cart_idle_days' => (int) env('GUEST_CART_IDLE_DAYS', 7),
    ],

];
