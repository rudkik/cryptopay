<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionStatus;
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
            // An invoice with money on chain that has not finished confirming
            // is not expired — it is waiting, and the watcher will resolve it
            // to `confirmed` or `orphaned`. InvoiceService::resolveStatus()
            // already refuses to move such an invoice, so processing it here is
            // a guaranteed no-op; excluding it in SQL is what makes that
            // guarantee matter. Sorted oldest-first and capped at --limit, a
            // handful of invoices stuck on a transaction that never resolves
            // would otherwise sit permanently at the head of every run and
            // starve every invoice behind them.
            //
            // Scoped to the invoice's own currency, because a USDC transfer to
            // a USDT invoice's address never settles that invoice and must not
            // hold its expiry open (see InvoiceService::recalculate()).
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('transactions')
                ->whereColumn('transactions.invoice_id', 'invoices.id')
                ->whereColumn('transactions.currency', 'invoices.currency')
                ->where('transactions.status', TransactionStatus::Detected->value))
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
