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
    /**
     * Leading hex of every simulated hash. Real hashes are uniformly random, so
     * this is how the simulator recognises its own rows without breaking the
     * per-network hash format the ingest endpoint enforces.
     */
    public const MARKER = 'f00dbabe';

    public function __construct(private readonly TransactionIngestService $ingest) {}

    /**
     * Simulated payments mint a confirmed credit out of nothing, so the config
     * flag alone is not enough to enable them: `.env.example` ships
     * SIMULATION_ENABLED=true, and a production deployment that inherits it
     * would hand every admin a mint button. The environment must agree.
     */
    public function isEnabled(): bool
    {
        return (bool) config('services.simulation.enabled')
            && app()->environment(['local', 'testing']);
    }

    /**
     * @return array{ok: bool, transaction_id: ?string, invoice_id: ?string, payload: array<string, mixed>}
     */
    public function simulate(Invoice $invoice, ?string $amount = null, bool $confirmed = true): array
    {
        if (! $this->isEnabled()) {
            throw new ForbiddenException(
                'Payment simulation is disabled. It requires SIMULATION_ENABLED=true and a local or testing environment.'
            );
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
            $blockHash = $pending->block_hash ?? self::fakeHash($isEvm);
            $fromAddress = $pending->from_address;
        } else {
            $amount = Money::normalize($amount ?? $invoice->amount);
            $txHash = self::fakeHash($isEvm);
            $blockNumber = (int) (($network?->last_scanned_block ?? 0) + 1);
            $blockHash = self::fakeHash($isEvm);
            $fromAddress = $isEvm
                ? '0x'.self::MARKER.bin2hex(random_bytes(16))
                : 'TSimulated'.Str::upper(Str::random(24));
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
            ->where(fn ($q) => $q
                ->where('tx_hash', 'like', self::MARKER.'%')
                ->orWhere('tx_hash', 'like', '0x'.self::MARKER.'%'))
            ->orderBy('created_at')
            ->first();
    }

    /**
     * A hash the ingest endpoint will accept (EVM: 0x + 64 hex, Tron: 64 hex)
     * that is still recognisable as simulated by its leading marker.
     */
    private static function fakeHash(bool $isEvm): string
    {
        $hex = self::MARKER.bin2hex(random_bytes(28));

        return $isEvm ? '0x'.$hex : $hex;
    }
}
