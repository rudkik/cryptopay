<?php

namespace App\Services;

use App\Enums\NetworkCode;
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
 * in the watcher (SPEC §3) — Laravel only owns `wallets.next_index`.
 */
class AddressService
{
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
                $wallet = Wallet::create(['network_code' => $networkCode, 'next_index' => 0]);
                $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
            }

            $index = (int) $wallet->next_index;
            $address = $this->derive($networkCode, $index);

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

    /** Call the watcher's derivation endpoint (SPEC §6.6). */
    public function derive(string $networkCode, int $index): string
    {
        $url = rtrim((string) config('services.watcher.url'), '/').'/addresses/derive';

        try {
            $response = Http::withHeaders([
                'X-Internal-Token' => (string) config('services.internal.token'),
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('services.watcher.timeout', 10))
                ->post($url, ['network' => $networkCode, 'index' => $index]);
        } catch (Throwable $e) {
            Log::error('Watcher derive request failed', ['network' => $networkCode, 'index' => $index, 'error' => $e->getMessage()]);

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
