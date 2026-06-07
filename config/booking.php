<?php

return [

    'max_nights' => (int) env('BOOKING_MAX_NIGHTS', 30),

    'hold_duration_minutes' => (int) env('BOOKING_HOLD_DURATION_MINUTES', 15),

    'tax_rate_bps' => (int) env('BOOKING_TAX_RATE_BPS', 0),

    'reference_prefix' => env('BOOKING_REFERENCE_PREFIX', 'BK'),

    'external_api' => [
        'url' => env('EXTERNAL_BOOKING_API_URL'),
        'key' => env('EXTERNAL_BOOKING_API_KEY'),
        'timeout' => (int) env('EXTERNAL_BOOKING_API_TIMEOUT', 10),
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('BOOKING_CIRCUIT_BREAKER_THRESHOLD', 5),
        'window_seconds' => (int) env('BOOKING_CIRCUIT_BREAKER_WINDOW', 60),
    ],

];
