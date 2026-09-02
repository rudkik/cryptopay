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
 *         ->middleware('cryptopay.webhook');
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
            return response()->json([
                'error' => [
                    'code' => 'invalid_signature',
                    'message' => $e->getMessage(),
                    'details' => new \stdClass(),
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $request->attributes->set('cryptopay_event', $event);

        return $next($request);
    }
}
