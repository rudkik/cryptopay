<?php

namespace App\Services;

use App\Enums\NetworkCode;
use App\Enums\TransactionStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\InvalidStateException;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Support\Str;

/**
 * Builds exactly the payload the watcher would POST to
 * /api/internal/transactions and feeds it through the real ingest pipeline, so
 * the demo/QA path exercises the production code (SPEC §6.4).
 */
class PaymentSimulator
{
    public function __construct(private readonly TransactionIngestService $ingest) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.simulation.enabled');
    }

    /**
     * @return array{ok: bool, transaction_id: ?string, invoice_id: ?string, payload: array<string, mixed>}
     */
    public function simulate(Invoice $invoice, ?string $amount = null, bool $confirmed = true): array
    {
        if (! $this->isEnabled()) {
            throw new ForbiddenException('Payment simulation is disabled. Set SIMULATION_ENABLED=true to use it.');
        }

        $invoice->loadMissing('depositAddress');

        if (! $invoice->depositAddress) {
            throw new InvalidStateException('This invoice has no deposit address.');
        }

        $registry = NetworkRegistry::make();
        $network = $registry->network($invoice->network_code);
        $contract = $registry->contract($invoice->network_code, $invoice->currency);
        $decimals = $contract?->decimals ?? 6;

        $isEvm = NetworkCode::isEvmCode($invoice->network_code);

        // Confirming an invoice that already has a simulated, still-unconfirmed
        // transaction re-sends THAT transaction (same hash/block) with enough
        // confirmations — exactly what the watcher does — instead of creating a
        // second payment. An explicit different amount always creates a new one.
        $pending = $confirmed ? $this->pendingSimulated($invoice) : null;
        if ($pending && ($amount === null || Money::cmp(Money::normalize($amount), $pending->amount) === 0)) {
            $amount = Money::normalize($pending->amount);
            $txHash = $pending->tx_hash;
            $blockNumber = (int) $pending->block_number;
            $blockHash = $pending->block_hash ?? '0xsim'.bin2hex(random_bytes(30));
            $fromAddress = $pending->from_address;
        } else {
            $amount = Money::normalize($amount ?? $invoice->amount);
            $txHash = '0xsim'.bin2hex(random_bytes(30));
            $blockNumber = (int) (($network?->last_scanned_block ?? 0) + 1);
            $blockHash = '0xsim'.bin2hex(random_bytes(30));
            $fromAddress = $isEvm ? '0xs1m'.str_repeat('a', 36) : 'TSimulated'.Str::upper(Str::random(24));
        }

        $payload = [
            'network' => $invoice->network_code,
            'tx_hash' => $txHash,
            'log_index' => 0,
            'contract_address' => $contract?->contract_address,
            'symbol' => $invoice->currency,
            'from_address' => $fromAddress,
            'to_address' => $invoice->depositAddress->address,
            'amount_raw' => Money::toBaseUnits($amount, $decimals),
            'amount' => Money::format($amount, $decimals),
            'block_number' => $blockNumber,
            'block_hash' => $blockHash,
            'confirmations' => $confirmed ? max(1, (int) ($network?->confirmations_required ?? 1)) : 1,
            'status' => $confirmed ? TransactionStatus::Confirmed->value : TransactionStatus::Detected->value,
            'raw' => ['simulated' => true, 'simulated_at' => now()->toIso8601String()],
        ];

        $result = $this->ingest->ingest($payload);

        return $result + ['payload' => $payload];
    }

    private function pendingSimulated(Invoice $invoice): ?Transaction
    {
        return $invoice->transactions()
            ->where('status', TransactionStatus::Detected->value)
            ->where('tx_hash', 'like', '0xsim%')
            ->orderBy('created_at')
            ->first();
    }
}
