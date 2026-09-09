<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'internal' => [
        'token' => env('INTERNAL_API_TOKEN'),
    ],

    'watcher' => [
        'url' => env('WATCHER_URL', 'http://watcher:3100'),
        'timeout' => (int) env('WATCHER_TIMEOUT', 10),
    ],

    'webhooks' => [
        // Local development only: allows a webhook_url that points at a private
        // or loopback address (host.docker.internal, 127.0.0.1, a compose
        // service name). Never enable this on a reachable deployment.
        'allow_private' => filter_var(env('WEBHOOK_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOLEAN),
        'timeout' => min(10, max(1, (int) env('WEBHOOK_TIMEOUT', 10))),
    ],

    'wallet' => [
        // How long a pooled receiving address stays reserved for an invoice
        // *after* the invoice expires, so a payment that lands late still
        // matches its own invoice instead of the next one on the address.
        'lease_grace' => max(0, (int) env('ADDRESS_LEASE_GRACE_SECONDS', 1800)),
    ],

    'simulation' => [
        'enabled' => filter_var(env('SIMULATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
