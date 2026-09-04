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

    /**
     * How long a never-attempted delivery may sit past its due time before this
     * command assumes the job WebhookService::create() queued for it is gone
     * (worker killed mid-job, Redis flushed) and queues a replacement.
     */
    private const STRANDED_AFTER_MINUTES = 5;

    public function handle(): int
    {
        $due = WebhookDelivery::query()
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            // A delivery with no attempt yet was queued by
            // WebhookService::create() moments ago with next_attempt_at = now,
            // so every run of this command would queue a second, third and
            // fourth job for it while the first is still waiting its turn.
            // Only deliveries the queue has actually touched (attempts > 0,
            // whose job has already returned and left nothing queued) are due
            // for a fresh dispatch — plus anything so far past due that its
            // original job must have been lost.
            ->where(fn ($q) => $q
                ->where('attempts', '>', 0)
                ->orWhere('next_attempt_at', '<=', now()->subMinutes(self::STRANDED_AFTER_MINUTES)))
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
