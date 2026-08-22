<?php

return [

    'timezone' => env('STORE_TIMEZONE', 'Asia/Manila'),


    'origin' => [
        'latitude' => (float) env('STORE_ORIGIN_LAT', 14.958753194320153),
        'longitude' => (float) env('STORE_ORIGIN_LNG', 120.75846924744896),
    ],


    'service_radius_km' => (float) env('STORE_SERVICE_RADIUS_KM', 45.0),


    'hours' => [
        'opens_at' => env('STORE_OPENS_AT', '07:00'),
        'closes_at' => env('STORE_CLOSES_AT', '20:00'),
    ],


    'pickup' => [
        'lead_minutes' => (int) env('STORE_PICKUP_LEAD_MINUTES', 20),
        'last_order_buffer_minutes' => (int) env('STORE_PICKUP_BUFFER_MINUTES', 15),
    ],

];
