<?php

namespace App\Services;

use App\Enums\NetworkCode;
use App\Exceptions\WatcherUnavailableException;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Owns "where the money goes": the account-level xpub each network's deposit
 * addresses are derived from (SPEC §3, §6.4).
 *
 * Resolution order for a network:
 *   1. `wallets.xpub` — set by an admin on the Wallet page, wins;
 *   2. the watcher's own `EVM_XPUB` / `TRON_XPUB`, reported by `/health` as
 *      `derivation.evm` / `derivation.tron` — the fallback;
 *   3. nothing — the network cannot hand out an address and is hidden from
 *      every currency/network picker.
 *
 * The xpub itself never leaves this class in full: callers get it only through
 * AddressService (straight into the watcher request body) or masked.
 */
class WalletService
{
    /**
     * Both the per-network verdict and the watcher probe are cached for 30s.
     * They gate `selectionOptions()`, which is rebuilt on a checkout page that
     * polls every 5s, so an uncached probe would be an HTTP call per poll.
     */
    public const CACHE_TTL = 30;

    /** A failed probe is cached far more briefly so recovery is not delayed. */
    private const FAILURE_TTL = 5;

    private const CONFIGURED_KEY = 'wallet:configured:';

    private const DERIVATION_KEY = 'watcher:derivation';

    public function wallet(string $networkCode): Wallet
    {
        $wallet = Wallet::query()->where('network_code', $networkCode)->first();

        if ($wallet) {
            return $wallet;
        }

        return Wallet::create([
            'network_code' => $networkCode,
            'next_index' => 0,
            'derivation_path' => Wallet::defaultPathFor($networkCode),
        ]);
    }

    /** 'database' | 'env' | 'none' — where this network's xpub comes from. */
    public function source(string $networkCode): string
    {
        if (Wallet::query()->where('network_code', $networkCode)->whereNotNull('xpub')->exists()) {
            return 'database';
        }

        return $this->watcherHasEnvXpub($networkCode) ? 'env' : 'none';
    }

    /** Can this network hand out a deposit address right now? Cached 30s. */
    public function isConfigured(string $networkCode): bool
    {
        $cached = Cache::get(self::CONFIGURED_KEY.$networkCode);

        if (is_bool($cached)) {
            return $cached;
        }

        $configured = $this->source($networkCode) !== 'none';

        Cache::put(
            self::CONFIGURED_KEY.$networkCode,
            $configured,
            $configured ? self::CACHE_TTL : self::FAILURE_TTL,
        );

        return $configured;
    }

    /**
     * `derivation.evm` / `derivation.tron` from the watcher's /health (SPEC
     * §6.6): whether its env xpub for that chain family is loaded. Cached; a
     * probe that fails is treated as "not ready" rather than fatal, because
     * this feeds a listing, not an allocation.
     *
     * @return array{evm: bool, tron: bool}
     */
    public function watcherDerivation(): array
    {
        $cached = Cache::get(self::DERIVATION_KEY);

        if (is_array($cached)) {
            return ['evm' => (bool) ($cached['evm'] ?? false), 'tron' => (bool) ($cached['tron'] ?? false)];
        }

        $url = rtrim((string) config('services.watcher.url'), '/').'/health';

        try {
            $response = Http::acceptJson()
                // Deliberately shorter than the allocation timeout: this runs
                // on read paths and must never be what makes a page slow.
                ->timeout(min(5, (int) config('services.watcher.timeout', 10)))
                ->get($url);
        } catch (Throwable $e) {
            Log::warning('Watcher derivation probe failed', ['error' => $e->getMessage()]);
            Cache::put(self::DERIVATION_KEY, ['evm' => false, 'tron' => false], self::FAILURE_TTL);

            return ['evm' => false, 'tron' => false];
        }

        $ok = $response->successful();

        $flags = [
            'evm' => $ok && (bool) $response->json('derivation.evm', false),
            'tron' => $ok && (bool) $response->json('derivation.tron', false),
        ];

        Cache::put(self::DERIVATION_KEY, $flags, $ok ? self::CACHE_TTL : self::FAILURE_TTL);

        return $flags;
    }

    public function watcherHasEnvXpub(string $networkCode): bool
    {
        $family = NetworkCode::isEvmCode($networkCode) ? 'evm' : 'tron';

        return $this->watcherDerivation()[$family];
    }

    /**
     * Derive the first `$count` addresses from a candidate xpub *without*
     * storing anything, so an operator can compare them against their own
     * wallet app before committing (SPEC §6.4).
     *
     * @return list<array{index: int, path: string, address: string}>
     *
     * @throws ValidationException when the watcher rejects the key
     */
    public function preview(string $networkCode, string $xpub, int $count = 5): array
    {
        $url = rtrim((string) config('services.watcher.url'), '/').'/addresses/derive-batch';

        try {
            $response = Http::withHeaders([
                'X-Internal-Token' => (string) config('services.internal.token'),
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('services.watcher.timeout', 10))
                ->post($url, [
                    'network' => $networkCode,
                    'from' => 0,
                    'count' => $count,
                    'xpub' => $xpub,
                ]);
        } catch (Throwable $e) {
            // The message can carry the request body on some transports.
            Log::error('Watcher xpub preview failed', ['network' => $networkCode]);

            throw new WatcherUnavailableException;
        }

        if ($response->status() === 422) {
            throw ValidationException::withMessages([
                'xpub' => [$this->validationMessage($response->json())],
            ]);
        }

        if (! $response->successful()) {
            Log::error('Watcher xpub preview returned an error', [
                'network' => $networkCode,
                'status' => $response->status(),
            ]);

            throw new WatcherUnavailableException;
        }

        $addresses = $response->json('addresses');

        if (! is_array($addresses) || $addresses === []) {
            throw new WatcherUnavailableException('The watcher returned no addresses for this key.');
        }

        return array_values(array_map(fn (array $item) => [
            'index' => (int) ($item['index'] ?? 0),
            'path' => (string) ($item['path'] ?? ''),
            'address' => (string) ($item['address'] ?? ''),
        ], $addresses));
    }

    /**
     * Persist a validated xpub for one network. Never resets `next_index`:
     * addresses already issued keep belonging to the previous key and stay
     * monitored, and reusing an index under a new key would hand out an
     * address a second time.
     *
     * @param  list<string>  $networkCodes  one network, or both EVM ones
     * @return list<Wallet>
     */
    public function store(array $networkCodes, string $xpub, ?string $label, ?User $user): array
    {
        return DB::transaction(function () use ($networkCodes, $xpub, $label, $user) {
            $saved = [];

            foreach ($networkCodes as $networkCode) {
                $wallet = $this->wallet($networkCode);

                $wallet->forceFill([
                    'xpub' => $xpub,
                    'derivation_path' => Wallet::defaultPathFor($networkCode),
                    'label' => $label,
                    'xpub_set_at' => now(),
                    'xpub_set_by' => $user?->getKey(),
                ])->save();

                $this->forget($networkCode);
                $saved[] = $wallet;
            }

            return $saved;
        });
    }

    /** Clear the stored xpub, falling back to the watcher's env key. */
    public function clear(string $networkCode): Wallet
    {
        $wallet = $this->wallet($networkCode);

        $wallet->forceFill([
            'xpub' => null,
            'label' => null,
            'xpub_set_at' => null,
            'xpub_set_by' => null,
        ])->save();

        $this->forget($networkCode);

        return $wallet;
    }

    public function forget(?string $networkCode = null): void
    {
        if ($networkCode !== null) {
            Cache::forget(self::CONFIGURED_KEY.$networkCode);

            return;
        }

        Cache::forget(self::DERIVATION_KEY);

        foreach (NetworkCode::cases() as $case) {
            Cache::forget(self::CONFIGURED_KEY.$case->value);
        }
    }

    /** Surface the watcher's own reason, without ever echoing the key back. */
    private function validationMessage(mixed $payload): string
    {
        $detail = data_get($payload, 'error.details.xpub.0');

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        $message = data_get($payload, 'error.message');

        return is_string($message) && $message !== ''
            ? $message
            : 'This is not a valid account-level xpub.';
    }
}
