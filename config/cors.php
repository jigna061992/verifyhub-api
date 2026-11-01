<?php

return [
    // Only the API is open to other origins
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // The website addresses that may call this API, comma separated in .env:
    // CORS_ALLOWED_ORIGINS=https://app.yourdomain.com
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Lets the browser read the file name of CSV / PDF downloads
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 600,

    // The app signs in with a Bearer token, not cookies
    'supports_credentials' => false,
];
