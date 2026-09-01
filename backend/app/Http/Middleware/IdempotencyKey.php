<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` support for creation endpoints (SPEC §6.1): the first
 * successful response for a key is replayed for 24h.
 */
class IdempotencyKey
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return $next($request);
        }

        $merchant = $request->attributes->get('merchant');
        $merchantId = $merchant instanceof Merchant ? $merchant->id : 'anonymous';

        $cacheKey = 'idempotency:'.hash('sha256', implode('|', [
            $merchantId,
            $request->method(),
            $request->path(),
            $key,
        ]));

        if ($cached = Cache::get($cacheKey)) {
            return response($cached['body'], $cached['status'])
                ->header('Content-Type', 'application/json')
                ->header('Idempotent-Replay', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
            ], self::TTL_SECONDS);
        }

        return $response;
    }
}
