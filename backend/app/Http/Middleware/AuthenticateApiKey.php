<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorResponse;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant API auth: `Authorization: Bearer cp_live_<40 hex>` (SPEC §6.1).
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $apiKeys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $request->bearerToken();

        if (! $plaintext) {
            return ErrorResponse::make('unauthenticated', 'API key missing. Send it as `Authorization: Bearer cp_live_...`.', 401);
        }

        $apiKey = $this->apiKeys->resolve($plaintext);

        if (! $apiKey) {
            return ErrorResponse::make('unauthenticated', 'Invalid or revoked API key.', 401);
        }

        if (! $apiKey->merchant || ! $apiKey->merchant->is_active) {
            return ErrorResponse::make('forbidden', 'This merchant account is disabled.', 403);
        }

        $this->apiKeys->touch($apiKey);

        // Deliberately not setUserResolver(): a Merchant is not Authenticatable,
        // and framework internals that call $request->user()->getAuthIdentifier()
        // would fatal. Controllers read the merchant off the request attributes.
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('merchant', $apiKey->merchant);

        return $next($request);
    }
}
