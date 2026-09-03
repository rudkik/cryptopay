<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Models\Balance;
use App\Models\DepositAddress;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for on-chain money (SPEC §6.5). Both the watcher and
 * the admin payment simulator go through here.
 *
 * Idempotency: transactions are keyed on (network, tx_hash, log_index) and the
 * merchant balance is credited exactly once, guarded by `credited_at` inside a
 * locked DB transaction.
 */
class TransactionIngestService
{
    /**
     * Attributes that describe settled money. Once `credited_at` is set they
     * are frozen against replays (see persist()).
     */
    private const SETTLED_FIELDS = [
        'invoice_id', 'merchant_id', 'network_code', 'tx_hash', 'log_index',
        'from_address', 'to_address', 'currency', 'contract_address',
        'amount', 'amount_raw', 'block_number', 'block_hash',
    ];

    public function __construct(
        private readonly AddressService $addresses,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, transaction_id: ?string, invoice_id: ?string, ignored?: bool}
     */
    public function ingest(array $payload): array
    {
        $networkCode = (string) $payload['network'];
        $toAddress = (string) $payload['to_address'];

        $depositAddress = $this->addresses->findByAddress($networkCode, $toAddress);

        if (! $depositAddress) {
            // Not one of ours: acknowledge so the watcher does not retry forever.
            Log::info('Ignoring transaction for an unknown address', [
                'network' => $networkCode,
                'to_address' => $toAddress,
                'tx_hash' => $payload['tx_hash'] ?? null,
            ]);

            return ['ok' => true, 'transaction_id' => null, 'invoice_id' => null, 'ignored' => true];
        }

        [$transaction, $shouldRecalculate] = DB::transaction(
            fn () => $this->persist($payload, $depositAddress)
        );

        $invoice = $transaction->invoice_id ? Invoice::find($transaction->invoice_id) : null;

        if ($invoice && $shouldRecalculate) {
            $this->invoices->recalculate($invoice);
        }

        return [
            'ok' => true,
            'transaction_id' => $transaction->id,
            'invoice_id' => $transaction->invoice_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Transaction, 1: bool}
     */
    private function persist(array $payload, DepositAddress $depositAddress): array
    {
        $networkCode = (string) $payload['network'];
        $txHash = (string) $payload['tx_hash'];
        $logIndex = (int) ($payload['log_index'] ?? 0);

        $transaction = Transaction::query()
            ->where('network_code', $networkCode)
            ->where('tx_hash', $txHash)
            ->where('log_index', $logIndex)
            ->lockForUpdate()
            ->first();

        $invoice = $depositAddress->invoice_id
            ? Invoice::query()->whereKey($depositAddress->invoice_id)->first()
            : null;

        $merchantId = $invoice?->merchant_id ?? $depositAddress->merchant_id;

        $registry = NetworkRegistry::make();
        $symbol = (string) ($payload['symbol'] ?? $invoice?->currency ?? 'USDT');
        $contract = $registry->contract($networkCode, $symbol);

        $amount = isset($payload['amount']) && $payload['amount'] !== null
            ? Money::normalize($payload['amount'])
            : Money::fromBaseUnits($payload['amount_raw'] ?? '0', $contract?->decimals ?? 6);

        $status = TransactionStatus::from((string) ($payload['status'] ?? TransactionStatus::Detected->value));

        $attributes = [
            'invoice_id' => $invoice?->id,
            'deposit_address_id' => $depositAddress->id,
            'merchant_id' => $merchantId,
            'network_code' => $networkCode,
            'tx_hash' => $txHash,
            'log_index' => $logIndex,
            'from_address' => $payload['from_address'] ?? null,
            'to_address' => (string) $payload['to_address'],
            'currency' => $symbol,
            'contract_address' => $payload['contract_address'] ?? $contract?->contract_address,
            'amount' => $amount,
            'amount_raw' => isset($payload['amount_raw']) ? (string) $payload['amount_raw'] : Money::toBaseUnits($amount, $contract?->decimals ?? 6),
            'block_number' => $payload['block_number'] ?? null,
            'block_hash' => $payload['block_hash'] ?? null,
            'confirmations' => (int) ($payload['confirmations'] ?? 0),
            'status' => $status->value,
            'raw' => $payload['raw'] ?? null,
        ];

        if ($transaction) {
            $previousStatus = $transaction->status;

            // A replayed payload must never lower the confirmation count.
            $attributes['confirmations'] = max((int) $transaction->confirmations, $attributes['confirmations']);

            // Money that has already been credited is immutable. Without this,
            // a replay carrying a different `amount` would rewrite the row the
            // ledger was built from: the invoice would recalculate against the
            // new figure (paid without a matching credit) and a later
            // `orphaned` would reverse the new figure instead of the credited
            // one, driving the balance negative.
            if ($transaction->credited_at !== null) {
                foreach (self::SETTLED_FIELDS as $field) {
                    unset($attributes[$field]);
                }
            }

            if ($this->isDowngrade($previousStatus, $status)) {
                // The watcher re-sends a transaction whenever its confirmation
                // count changes, and after a manual /rescan it can re-announce
                // an already-confirmed transaction as `detected`. Settled money
                // must not be un-settled by that: only `orphaned`/`failed` may
                // override `confirmed`.
                $transaction->forceFill(['confirmations' => $attributes['confirmations']])->save();

                return [$transaction, false];
            }

            $transaction->forceFill($attributes)->save();
        } else {
            $previousStatus = null;
            $transaction = Transaction::create($attributes);
        }

        $statusChanged = $previousStatus !== $status;

        if ($status === TransactionStatus::Confirmed) {
            $this->credit($transaction);
        } elseif ($status === TransactionStatus::Orphaned || $status === TransactionStatus::Failed) {
            $this->reverse($transaction);
        }

        $this->syncPending($transaction);

        return [$transaction, $statusChanged || $previousStatus === null];
    }

    /**
     * A confirmed transaction is never walked back to `detected`. That happens
     * when the watcher re-announces a settled transaction after a /rescan.
     * `orphaned` and `failed` may still supersede it (a reorg is real news),
     * and a repeated `confirmed` falls through to the `credited_at` guard.
     */
    private function isDowngrade(TransactionStatus $current, TransactionStatus $incoming): bool
    {
        return $current === TransactionStatus::Confirmed
            && $incoming === TransactionStatus::Detected;
    }

    /**
     * Credit the merchant's available balance exactly once for a confirmed
     * transaction. `credited_at` is the idempotency guard.
     */
    private function credit(Transaction $transaction): void
    {
        if ($transaction->credited_at !== null || ! $transaction->merchant_id) {
            return;
        }

        if (! Money::isPositive($transaction->amount)) {
            $transaction->forceFill(['credited_at' => now()])->save();

            return;
        }

        $balance = $this->lockedBalance($transaction->merchant_id, $transaction->currency, $transaction->network_code);

        $available = Money::add($balance->available, $transaction->amount);

        $balance->forceFill(['available' => $available])->save();

        LedgerEntry::create([
            'merchant_id' => $transaction->merchant_id,
            'currency' => $transaction->currency,
            'network_code' => $transaction->network_code,
            'amount' => $transaction->amount,
            'type' => LedgerEntryType::Deposit->value,
            'transaction_id' => $transaction->id,
            'balance_after' => $available,
            'note' => 'Deposit '.$transaction->tx_hash,
        ]);

        $transaction->forceFill(['credited_at' => now()])->save();
    }

    /**
     * Reverse a credit when a confirmed transaction is later orphaned by a
     * reorg.
     *
     * The amount reversed is the transaction's *net* effect on the ledger, not
     * whatever `amount` the row currently carries. Those are normally the same
     * value, but deriving it from the ledger makes it impossible for a reversal
     * to take out more than was ever put in, however the row was replayed.
     */
    private function reverse(Transaction $transaction): void
    {
        if ($transaction->credited_at === null || ! $transaction->merchant_id) {
            return;
        }

        $credited = Money::normalize(
            LedgerEntry::query()->where('transaction_id', $transaction->id)->sum('amount')
        );

        if (! Money::isPositive($credited)) {
            // Nothing was ever moved for this transaction (a zero-amount
            // credit, or it has already been reversed).
            $transaction->forceFill(['credited_at' => null])->save();

            return;
        }

        $balance = $this->lockedBalance($transaction->merchant_id, $transaction->currency, $transaction->network_code);

        $available = Money::sub($balance->available, $credited);

        if (Money::cmp($available, '0') < 0) {
            // Unreachable while credits and reversals stay paired; if it ever
            // happens the balance is wrong either way, so keep it at zero and
            // make the inconsistency loud instead of silently going negative.
            Log::critical('Reversal would drive a balance negative; clamped to zero', [
                'merchant_id' => $transaction->merchant_id,
                'currency' => $transaction->currency,
                'network' => $transaction->network_code,
                'transaction_id' => $transaction->id,
            ]);

            $credited = Money::normalize($balance->available);
            $available = Money::zero();
        }

        $balance->forceFill(['available' => $available])->save();

        LedgerEntry::create([
            'merchant_id' => $transaction->merchant_id,
            'currency' => $transaction->currency,
            'network_code' => $transaction->network_code,
            'amount' => Money::sub('0', $credited),
            'type' => LedgerEntryType::Adjustment->value,
            'transaction_id' => $transaction->id,
            'balance_after' => $available,
            'note' => 'Reversed '.$transaction->status->value.' transaction '.$transaction->tx_hash,
        ]);

        $transaction->forceFill(['credited_at' => null])->save();
    }

    /**
     * `pending` is derived, never incremented: it is the sum of the merchant's
     * still-unconfirmed transactions for that currency/network. Recomputing it
     * makes double counting impossible however payloads are replayed.
     */
    private function syncPending(Transaction $transaction): void
    {
        if (! $transaction->merchant_id) {
            return;
        }

        $pending = Transaction::query()
            ->where('merchant_id', $transaction->merchant_id)
            ->where('currency', $transaction->currency)
            ->where('network_code', $transaction->network_code)
            ->where('status', TransactionStatus::Detected->value)
            ->sum('amount');

        $balance = $this->lockedBalance($transaction->merchant_id, $transaction->currency, $transaction->network_code);

        $balance->forceFill(['pending' => Money::normalize($pending)])->save();
    }

    private function lockedBalance(string $merchantId, string $currency, string $networkCode): Balance
    {
        $balance = Balance::query()
            ->where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->where('network_code', $networkCode)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            return $balance;
        }

        return Balance::create([
            'merchant_id' => $merchantId,
            'currency' => $currency,
            'network_code' => $networkCode,
            'available' => Money::zero(),
            'pending' => Money::zero(),
        ]);
    }
}
