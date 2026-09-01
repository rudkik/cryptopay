<?php

namespace App\Console\Commands;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Console\Command;

/**
 * Re-dispatches webhook deliveries whose `next_attempt_at` is due (SPEC §6.2).
 */
class RetryWebhooksCommand extends Command
{
    protected $signature = 'webhooks:retry {--limit=200 : Maximum deliveries to re-dispatch in one run}';

    protected $description = 'Re-queue webhook deliveries that are due for another attempt';

    public function handle(): int
    {
        $due = WebhookDelivery::query()
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $delivery) {
            SendWebhookJob::dispatch($delivery->id)->onQueue(WebhookService::QUEUE);
        }

        $this->info("Re-queued {$due->count()} webhook deliver(y|ies).");

        return self::SUCCESS;
    }
}
