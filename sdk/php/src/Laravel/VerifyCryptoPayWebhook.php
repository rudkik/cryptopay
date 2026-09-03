<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Laravel;

use Closure;
use CryptoPay\Sdk\Exception\SignatureException;
use CryptoPay\Sdk\Webhook;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the X-CryptoPay-Signature header on an incoming webhook request
 * and stashes the decoded CryptoPay\Sdk\WebhookEvent on the request as the
 * `cryptopay_event` attribute.
 *
 * Register the `cryptopay.webhook` route middleware alias (done automatically
 * by CryptoPayServiceProvider) on your webhook route:
 *
 *     Route::post('/webhooks/cryptopay', WebhookController::class)
 *         ->middleware('cryptopay.webhook')
 *         ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
 *
 * CSRF: CryptoPay is a server-to-server caller and has no session cookie or CSRF
 * token, so a route in the `web` group would be rejected with 419 before this
 * middleware ever runs. Either declare the route in `routes/api.php` (no CSRF
 * middleware there) or exclude it explicitly — `withoutMiddleware()` as above,
 * or by adding the path to `$except` in your `VerifyCsrfToken` middleware
 * (Laravel <=10) / `$middleware->validateCsrfTokens(except: [...])` in
 * `bootstrap/app.php` (Laravel 11+). The signature check below is what
 * authenticates the request; CSRF protection adds nothing to it.
 *
 * IMPORTANT: nothing upstream of this middleware may read or mutate the raw
 * request body (e.g. via a custom body-parsing middleware) — the signature
 * is computed over the exact bytes CryptoPay sent.
 */
final class VerifyCryptoPayWebhook
{
    public function handle(Request $request, Closure $next): mixed
    {
        $secret = (string) config('cryptopay.webhook_secret', '');
        $tolerance = (int) config('cryptopay.webhook_tolerance', 300);

        try {
            $event = Webhook::verify(
                $request->getContent(),
                $request->headers->all(),
                $secret,
                $tolerance
            );
        } catch (SignatureException $e) {
            // The precise reason (missing header, stale timestamp, unconfigured
            // secret, bad signature) is useful to the operator and useful to an
            // attacker probing the endpoint, so it goes to the log and never
            // into the response body.
            if (function_exists('logger')) {
                logger()->warning('CryptoPay webhook rejected: '.$e->getMessage(), [
                    'ip' => $request->ip(),
                ]);
            }

            return response()->json([
                'error' => [
                    'code' => 'invalid_signature',
                    'message' => 'Invalid webhook signature.',
                    'details' => new \stdClass(),
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $request->attributes->set('cryptopay_event', $event);

        return $next($request);
    }
}
