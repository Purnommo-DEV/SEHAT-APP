<?php

return [
    'realtime' => [
        // Polling is the deployment-safe default. Reverb remains an opt-in
        // transport and uses the same endpoints and business transitions.
        'driver' => env('REALTIME_DRIVER', 'polling'),
        'polling_interval_ms' => (int) env('REALTIME_POLLING_INTERVAL_MS', 3000),
    ],

    'seed_admin' => [
        'name' => env('SEED_ADMIN_NAME', 'Administrator'),
        'email' => env('SEED_ADMIN_EMAIL', 'admin@sehat-app.test'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],
];
