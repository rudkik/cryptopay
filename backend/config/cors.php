<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | In the compose stack nginx serves the SPA and proxies /api to php-fpm, so
    | everything is same-origin and CORS is a no-op. The APP_URL origin is still
    | allowed explicitly so the API can be called from a separately hosted
    | frontend during development.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('APP_URL', 'http://localhost:8095'),
        env('FRONTEND_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
