<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Optional modules
    |--------------------------------------------------------------------------
    |
    | A flag here decides whether a whole slice of the API exists on this
    | deployment. Routes carrying the `feature:<flag>` middleware answer with
    | the SPEC §6 `not_found` envelope while their flag is off, and clients read
    | the same map from `GET /api/public/config` and the admin login / auth me
    | payloads so they can hide the corresponding UI.
    |
    */

    // Token sale (SPEC §6.1 /tokens, /token-purchases, /customers/{id}/holdings
    // and their admin counterparts). Off by default: most deployments only take
    // payments.
    'token_sale' => filter_var(env('TOKEN_SALE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

];
