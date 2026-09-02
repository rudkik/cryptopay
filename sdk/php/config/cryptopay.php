<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | CryptoPay API key
    |--------------------------------------------------------------------------
    |
    | Your merchant secret key, e.g. cp_live_.... Found in the CryptoPay
    | admin panel under Merchants > API keys.
    |
    */
    'api_key' => env('CRYPTOPAY_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | CryptoPay base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('CRYPTOPAY_BASE_URL', 'http://localhost:8095'),

    /*
    |--------------------------------------------------------------------------
    | Webhook secret
    |--------------------------------------------------------------------------
    |
    | Used by the VerifyCryptoPayWebhook middleware to check the
    | X-CryptoPay-Signature header on incoming webhook deliveries.
    |
    */
    'webhook_secret' => env('CRYPTOPAY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Webhook tolerance
    |--------------------------------------------------------------------------
    |
    | Maximum allowed drift, in seconds, between the webhook's timestamp and
    | now. Set to 0 or less to disable the check (not recommended).
    |
    */
    'webhook_tolerance' => (int) env('CRYPTOPAY_WEBHOOK_TOLERANCE', 300),

    /*
    |--------------------------------------------------------------------------
    | Request timeout
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('CRYPTOPAY_TIMEOUT', 30),
];
