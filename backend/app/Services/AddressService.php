<?php

namespace App\Services;

use App\Enums\NetworkCode;
use App\Exceptions\NoFreeAddressException;
use App\Exceptions\WalletNotConfiguredException;
use App\Exceptions\WatcherUnavailableException;
use App\Models\DepositAddress;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\ReceivingAddress;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides which address an invoice is paid to.
 *
 * Two sources, in this order:
 *
 *  1. The operator's static list (`receiving_addresses`, admin Addresses
 *     page). An enabled address on the invoice's network that accepts its
 *     currency is *leased* to the invoice: its `deposit_addresses` row gets
 *     `leased_until = now + invoice lifetime + grace`, and until then no other
 *     invoice can get the same address. Candidates are ordered by priority
 *     (lower first) and then least-recently-leased, so equal-priority
 *     addresses rotate. Payments are matched to the invoice holding the lease
 *     at the time they are ingested (TransactionIngestService reads
 *     `deposit_addresses.invoice_id`).
 *
 *  2. HD derivation from the network's xpub (SPEC §3): a fresh, never-reused
 *     address per invoice. Used when the list has nothing for the pair, and
 *     as the overflow when every listed address is busy.
 *
 * The HD derivation itself lives in the watcher — Laravel owns
 * `wallets.next_index` and, since the admin Wallet page exists, `wallets.xpub`.
 */
class AddressService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * Hand out an address for `$currency` on `$networkCode`: a leased static
     * address when one is free, otherwise a freshly derived HD address.
     *
     * `$leaseSeconds` is how long a static address is reserved (the invoice's
     * lifetime plus `services.wallet.lease_grace`). It is irrelevant for a
     * derived address, which belongs to its invoice forever.
     *
     * @throws NoFreeAddressException every listed address is busy and there is no xpub to fall back on
     * @throws WalletNotConfiguredException nothing is listed and no xpub exists
     */
    public function allocate(
        string $networkCode,
        Merchant $merchant,
        ?string $currency = null,
        ?int $leaseSeconds = null,
    ): DepositAddress {
        $leaseSeconds ??= 3600 + (int) config('services.wallet.lease_grace', 1800);

        $pool = $this->leaseFromPool($networkCode, $merchant, $currency, $leaseSeconds);

        if ($pool['address'] !== null) {
            return $pool['address'];
        }

        if ($pool['candidates'] > 0 && $currency !== null && ! $this->wallets->hasXpub($networkCode)) {
            throw new NoFreeAddressException($networkCode, $currency);
        }

        return $this->deriveAndStore($networkCode, $merchant);
    }

    /**
     * Try the static list. Runs in its own transaction: the candidate rows are
     * locked (`FOR UPDATE`) so two invoices created at the same instant cannot
     * both conclude the same address is free.
     *
     * @return array{address: ?DepositAddress, candidates: int}
     */
    private function leaseFromPool(string $networkCode, Merchant $merchant, ?string $currency, int $leaseSeconds): array
    {
        return DB::transaction(function () use ($networkCode, $merchant, $currency, $leaseSeconds) {
            $candidates = ReceivingAddress::query()
                ->where('network_code', $networkCode)
                ->where('is_enabled', true)
                ->orderBy('priority')
                ->orderByRaw('last_leased_at asc nulls first')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get()
                ->filter(fn (ReceivingAddress $row) => $currency === null || $row->accepts($currency))
                ->values();

            if ($candidates->isEmpty()) {
                return ['address' => null, 'candidates' => 0];
            }

            $leases = DepositAddress::query()
                ->whereIn('receiving_address_id', $candidates->pluck('id'))
                ->get()
                ->keyBy('receiving_address_id');

            foreach ($candidates as $candidate) {
                /** @var ?DepositAddress $lease */
                $lease = $leases->get($candidate->id);

                if ($lease && $lease->isLeased()) {
                    continue;
                }

                $now = now();

                if (! $lease) {
                    $lease = DepositAddress::create([
                        'network_code' => $networkCode,
                        'address' => $candidate->address,
                        'derivation_index' => null,
                        'receiving_address_id' => $candidate->id,
                        'merchant_id' => $merchant->id,
                        'is_active' => true,
                        'leased_until' => $now->copy()->addSeconds($leaseSeconds),
                    ]);
                } else {
                    // `invoice_id` is left pointing at the previous invoice
                    // until InvoiceService binds the new one: a payment that
                    // lands in that gap still matches the last lessee rather
                    // than nobody.
                    $lease->forceFill([
                        'merchant_id' => $merchant->id,
                        'is_active' => true,
                        'leased_until' => $now->copy()->addSeconds($leaseSeconds),
                    ])->save();
                }

                $candidate->forceFill(['last_leased_at' => $now])->save();

                $lease->setRelation('receivingAddress', $candidate);

                return ['address' => $lease, 'candidates' => $candidates->count()];
            }

            return ['address' => null, 'candidates' => $candidates->count()];
        });
    }

    /**
     * Give a leased static address back early — the invoice was cancelled, so
     * nothing more is expected on it. A derived address is untouched.
     */
    public function release(Invoice $invoice): void
    {
        if ($invoice->deposit_address_id === null) {
            return;
        }

        DepositAddress::query()
            ->whereKey($invoice->deposit_address_id)
            ->where('invoice_id', $invoice->id)
            ->whereNotNull('receiving_address_id')
            ->update(['leased_until' => now()]);
    }

    /**
     * Take the next derivation index under a row lock, derive the address via
     * the watcher, and persist it. If the watcher is unreachable the whole
     * transaction rolls back, so the index is never burned.
     */
    private function deriveAndStore(string $networkCode, Merchant $merchant): DepositAddress
    {
        return DB::transaction(function () use ($networkCode, $merchant) {
            $wallet = Wallet::query()
                ->where('network_code', $networkCode)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = Wallet::create([
                    'network_code' => $networkCode,
                    'next_index' => 0,
                    'derivation_path' => Wallet::defaultPathFor($networkCode),
                ]);
                $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            // The row is already locked and read, so the DB xpub is authoritative
            // here; the cached verdict only decides whether the watcher's env
            // fallback is there to catch a network with no stored key.
            if (! $wallet->hasXpub() && ! $this->wallets->watcherHasEnvXpub($networkCode)) {
                throw new WalletNotConfiguredException($networkCode);
            }

            $index = (int) $wallet->next_index;
            $address = $this->derive($networkCode, $index, $wallet->xpub);

            $wallet->forceFill(['next_index' => $index + 1])->save();

            return DepositAddress::create([
                'network_code' => $networkCode,
                'address' => $address,
                'derivation_index' => $index,
                'merchant_id' => $merchant->id,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Call the watcher's derivation endpoint (SPEC §6.6).
     *
     * `$xpub` is the key stored on the wallet row. Sent, it wins over the
     * watcher's env key; omitted, the watcher falls back to `EVM_XPUB` /
     * `TRON_XPUB`. It is never logged — not in the request log, not in the
     * error branches below.
     */
    public function derive(string $networkCode, int $index, ?string $xpub = null): string
    {
        $url = rtrim((string) config('services.watcher.url'), '/').'/addresses/derive';

        $payload = ['network' => $networkCode, 'index' => $index];

        if (is_string($xpub) && $xpub !== '') {
            $payload['xpub'] = $xpub;
        }

        try {
            $response = Http::withHeaders([
                'X-Internal-Token' => (string) config('services.internal.token'),
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('services.watcher.timeout', 10))
                ->post($url, $payload);
        } catch (Throwable $e) {
            // The exception message is not logged: a Guzzle transfer error
            // includes the request body, which would carry the xpub.
            Log::error('Watcher derive request failed', ['network' => $networkCode, 'index' => $index]);

            throw new WatcherUnavailableException;
        }

        if (! $response->successful()) {
            Log::error('Watcher derive returned an error', [
                'network' => $networkCode,
                'index' => $index,
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);

            throw new WatcherUnavailableException;
        }

        $address = (string) $response->json('address', '');

        if ($address === '') {
            throw new WatcherUnavailableException('The watcher returned an empty address.');
        }

        return $address;
    }

    /**
     * Find the deposit address a transaction was sent to. EVM addresses are
     * compared case-insensitively (EIP-55 checksums vary), Tron exactly.
     */
    public function findByAddress(string $networkCode, string $address): ?DepositAddress
    {
        $query = DepositAddress::query()->where('network_code', $networkCode);

        if (NetworkCode::isEvmCode($networkCode)) {
            $query->whereRaw('lower(address) = ?', [mb_strtolower($address)]);
        } else {
            $query->where('address', $address);
        }

        return $query->first();
    }
}
