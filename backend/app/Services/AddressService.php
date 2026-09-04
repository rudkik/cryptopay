<?php

namespace App\Services;

use App\Enums\NetworkCode;
use App\Exceptions\WalletNotConfiguredException;
use App\Exceptions\WatcherUnavailableException;
use App\Models\DepositAddress;
use App\Models\Merchant;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Allocates a fresh deposit address per invoice. The HD derivation itself lives
 * in the watcher (SPEC §3) — Laravel owns `wallets.next_index` and, since the
 * admin Wallet page exists, `wallets.xpub`.
 */
class AddressService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * Take the next derivation index under a row lock, derive the address via
     * the watcher, and persist it. If the watcher is unreachable the whole
     * transaction rolls back, so the index is never burned.
     */
    public function allocate(string $networkCode, Merchant $merchant): DepositAddress
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
            if (! $wallet->hasXpub() && ! $this->wallets->isConfigured($networkCode)) {
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
