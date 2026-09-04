<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    /** How long the loser of a race waits for the winner's response. */
    private const WAIT_SECONDS = 10;

    /** Released explicitly; the TTL only covers a request that dies mid-flight. */
    private const LOCK_SECONDS = 30;

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
            return $this->replay($cached);
        }

        // Serialise same-key requests instead of only de-duplicating the ones
        // that arrive after a response was cached. A client that retries on a
        // timeout usually retries while the first request is still running, and
        // a plain read-then-write would let both through: two invoices, two
        // burned derivation indexes, and the merchant's whole reason for
        // sending the header defeated. The loser waits, then finds and replays
        // the winner's cached response.
        $lock = Cache::lock($cacheKey.':lock', self::LOCK_SECONDS);

        try {
            $acquired = $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // Still holding on after the wait: fall through unlocked rather
            // than failing the request outright.
            $acquired = false;
        }

        try {
            if ($acquired && ($cached = Cache::get($cacheKey))) {
                return $this->replay($cached);
            }

            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /** @param  array{status: int, body: string}  $cached */
    private function replay(array $cached): Response
    {
        return response($cached['body'], $cached['status'])
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replay', 'true');
    }
}
