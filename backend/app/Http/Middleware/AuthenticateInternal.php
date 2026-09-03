<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watcher <-> backend auth: shared secret in `X-Internal-Token` (SPEC §6.5).
 *
 * This token is the only thing standing between the internet and the code path
 * that credits merchant balances, so an unset or placeholder value is treated
 * as a misconfigured deployment (503) rather than an endpoint that anyone who
 * has read the repository can call.
 */
class AuthenticateInternal
{
    /** The shipped placeholders from .env.example / SPEC §8. */
    private const PLACEHOLDER_PREFIXES = ['change-me', 'changeme', 'your-token', 'secret'];

    private const MIN_LENGTH = 16;

    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('services.internal.token'));

        if ($reason = self::misconfiguration($expected)) {
            // The reason names the problem, never the value.
            Log::critical('Internal API refused: INTERNAL_API_TOKEN is not usable', ['reason' => $reason]);

            return ErrorResponse::make(
                'server_error',
                'The internal API is not configured on this deployment.',
                503,
            );
        }

        $provided = (string) $request->header('X-Internal-Token', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return ErrorResponse::make('unauthenticated', 'Invalid internal token.', 401);
        }

        return $next($request);
    }

    /**
     * @return string|null null when the configured token is acceptable
     */
    public static function misconfiguration(string $token): ?string
    {
        if ($token === '') {
            return 'empty';
        }

        // Local and testing run from .env.example values on purpose.
        if (app()->environment(['local', 'testing'])) {
            return null;
        }

        $lower = mb_strtolower($token);

        foreach (self::PLACEHOLDER_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'placeholder value';
            }
        }

        if (mb_strlen($token) < self::MIN_LENGTH) {
            return 'shorter than '.self::MIN_LENGTH.' characters';
        }

        return null;
    }
}
