<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Only the FlatMeta app may call the API from a browser: the website plus the
    // iOS/Android app shells (Capacitor). Add more with CORS_ALLOWED_ORIGINS=a,b
    'allowed_origins' => array_values(array_filter(array_merge(
        [rtrim(env('FRONTEND_URL', 'http://localhost:4200'), '/'), 'capacitor://localhost', 'https://localhost', 'http://localhost'],
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
