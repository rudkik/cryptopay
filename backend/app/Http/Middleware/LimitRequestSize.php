<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps API request bodies well below php.ini's 20M post_max_size.
 *
 * Nothing this API accepts is large: the biggest legitimate body is a 200-item
 * transaction batch. Anything past the cap is a mistake or an attempt to make
 * the JSON parser do expensive work before validation ever runs.
 */
class LimitRequestSize
{
    public const DEFAULT_MAX_BYTES = 1048576;

    public function handle(Request $request, Closure $next, ?string $maxBytes = null): Response
    {
        $max = $maxBytes !== null ? (int) $maxBytes : self::DEFAULT_MAX_BYTES;

        $declared = (int) $request->headers->get('Content-Length', '0');
        $actual = mb_strlen((string) $request->getContent(), '8bit');

        if (max($declared, $actual) > $max) {
            return ErrorResponse::make(
                'validation_error',
                'The request body is too large. The limit is '.$max.' bytes.',
                413,
            );
        }

        return $next($request);
    }
}
