<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

/**
 * SPEC §5: pending/confirming invoices past `expires_at` become expired (nothing
 * received) or partially_paid (some confirmed but short of the amount).
 */
class ExpireInvoicesCommand extends Command
{
    protected $signature = 'invoices:expire {--limit=500 : Maximum invoices to process in one run}';

    protected $description = 'Expire invoices whose expires_at has passed';

    public function handle(InvoiceService $invoices): int
    {
        $processed = 0;

        Invoice::query()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Confirming->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['merchant', 'depositAddress'])
            ->orderBy('expires_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Invoice $invoice) use ($invoices, &$processed) {
                $before = $invoice->status;
                $invoices->expire($invoice);
                $processed++;

                if ($invoice->status !== $before) {
                    $this->line("Invoice {$invoice->id}: {$before->value} -> {$invoice->status->value}");
                }
            });

        $this->info("Processed {$processed} expiring invoice(s).");

        return self::SUCCESS;
    }
}
