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

    /*
     * Ceilings on what unauthenticated traffic may spend.
     *
     * The quote limits stand in for the OpenRouteService budget: routing happens
     * inside a checkout quote, so the route is the only place a limiter can sit.
     * That over-counts pickup quotes, which cost nothing - accepted deliberately,
     * and the daily figure is set high enough that pickup traffic cannot starve
     * delivery traffic while still leaving most of the 2,000/day free tier for
     * signed-in customers.
     *
     * Geocoding is separate because Nominatim's own gate already makes a ban
     * impossible; the risk there is a guest queueing ahead of a paying customer,
     * so that one is tight per IP.
     */
    'guest' => [
        'quote_per_minute' => (int) env('GUEST_QUOTE_PER_MINUTE', 40),
        'quote_per_day' => (int) env('GUEST_QUOTE_PER_DAY', 800),
        'geocode_per_minute' => (int) env('GUEST_GEOCODE_PER_MINUTE', 10),
        'geocode_per_day' => (int) env('GUEST_GEOCODE_PER_DAY', 500),
    ],

];
