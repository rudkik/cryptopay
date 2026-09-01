<?php

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one webhook attempt. Never throws: the delivery row itself carries
 * the retry state (`attempts`, `next_attempt_at`), and `webhooks:retry`
 * re-dispatches whatever is due.
 *
 * Deliberately not `afterCommit`: every WebhookService::create() call already
 * happens outside a database transaction, and an afterCommit job would never
 * fire under the test suite's wrapping transaction.
 */
class SendWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        $lock = Cache::lock('webhook-delivery:'.$this->deliveryId, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->deliver();
        } finally {
            $lock->release();
        }
    }

    private function deliver(): void
    {
        $delivery = WebhookDelivery::query()->with('merchant')->find($this->deliveryId);

        if (! $delivery || $delivery->status !== WebhookDeliveryStatus::Pending) {
            return;
        }

        if ($delivery->next_attempt_at && $delivery->next_attempt_at->isFuture()) {
            return;
        }

        $secret = (string) ($delivery->merchant?->webhook_secret ?? '');
        $body = (string) $delivery->payload;
        $timestamp = now()->getTimestamp();
        $signature = WebhookService::sign($secret, $timestamp, $body);

        $attempts = $delivery->attempts + 1;

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-CryptoPay-Event' => $delivery->event,
                    'X-CryptoPay-Delivery' => $delivery->id,
                    'X-CryptoPay-Timestamp' => (string) $timestamp,
                    'X-CryptoPay-Signature' => $signature,
                ])
                ->timeout(15)
                ->post($delivery->url);

            $status = $response->status();
            $responseBody = substr((string) $response->body(), 0, 2048);

            if ($response->successful()) {
                $delivery->forceFill([
                    'attempts' => $attempts,
                    'signature' => $signature,
                    'status' => WebhookDeliveryStatus::Delivered->value,
                    'response_code' => $status,
                    'response_body' => $responseBody,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                    'last_error' => null,
                ])->save();

                return;
            }

            $this->fail($delivery, $attempts, $signature, $status, $responseBody, 'HTTP '.$status);
        } catch (Throwable $e) {
            Log::warning('Webhook delivery failed', ['delivery' => $delivery->id, 'error' => $e->getMessage()]);

            $this->fail($delivery, $attempts, $signature, null, null, $e->getMessage());
        }
    }

    private function fail(
        WebhookDelivery $delivery,
        int $attempts,
        string $signature,
        ?int $responseCode,
        ?string $responseBody,
        string $error,
    ): void {
        $exhausted = $attempts >= $delivery->max_attempts;

        $delayIndex = min($attempts - 1, count(WebhookService::RETRY_DELAYS) - 1);

        $delivery->forceFill([
            'attempts' => $attempts,
            'signature' => $signature,
            'status' => $exhausted ? WebhookDeliveryStatus::Failed->value : WebhookDeliveryStatus::Pending->value,
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'last_error' => substr($error, 0, 2048),
            'next_attempt_at' => $exhausted ? null : now()->addSeconds(WebhookService::RETRY_DELAYS[$delayIndex]),
        ])->save();
    }
}
