<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorResponse;
use App\Support\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an optional module behind config/features.php — used as
 * `feature:token_sale` in routes/api.php.
 *
 * A disabled module answers 404 rather than 403: the flag decides whether those
 * paths are part of this deployment's API surface at all, so the reply is the
 * SPEC §6 `not_found` envelope, with a message that says which module is off so
 * an integrator is not left guessing at a typo.
 */
class FeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (FeatureFlags::enabled($feature)) {
            return $next($request);
        }

        // `token_sale` -> "Token sale module is disabled".
        $name = Str::ucfirst(str_replace('_', ' ', $feature));

        return ErrorResponse::make('not_found', "{$name} module is disabled", 404);
    }
}
