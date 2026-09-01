<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watcher <-> backend auth: shared secret in `X-Internal-Token` (SPEC §6.5).
 */
class AuthenticateInternal
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal.token');
        $provided = (string) $request->header('X-Internal-Token', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return ErrorResponse::make('unauthenticated', 'Invalid internal token.', 401);
        }

        return $next($request);
    }
}
