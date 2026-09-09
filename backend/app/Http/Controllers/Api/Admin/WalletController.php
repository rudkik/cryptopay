<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Currency;
use App\Enums\NetworkCode;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWalletXpubRequest;
use App\Models\DepositAddress;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\ReceivingAddress;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogger;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The deposit wallet per network — "where the money goes" (SPEC §3, §6.4).
 *
 * Reads are open to viewers; every mutation is behind role=admin and audited.
 * The stored xpub is only ever echoed back masked: an admin who needs the full
 * value has it in their own wallet app and can re-paste it.
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/admin/wallets */
    public function index(): JsonResponse
    {
        $counts = $this->addressCounts();
        $received = $this->receivedByNetwork();

        return response()->json(['data' => array_map(
            fn (Network $network) => $this->item($network, $counts, $received),
            $this->networks(),
        )]);
    }

    /**
     * POST /api/admin/wallets/{network}/preview
     *
     * Derives the first addresses from a candidate key so the operator can
     * compare them with their own wallet app *before* committing. Nothing is
     * written — not the key, not the label, not an audit entry.
     */
    public function preview(StoreWalletXpubRequest $request, string $network): JsonResponse
    {
        $this->networkOrFail($network);

        return response()->json([
            'addresses' => $this->wallets->preview($network, (string) $request->validated('xpub')),
        ]);
    }

    /** PUT /api/admin/wallets/{network} */
    public function update(StoreWalletXpubRequest $request, string $network): JsonResponse
    {
        $model = $this->networkOrFail($network);
        $xpub = (string) $request->validated('xpub');
        $label = $request->validated('label') ?: null;

        // Validated by deriving: the watcher is the only BIP32 authority here,
        // and a 422 from it becomes a field error on `xpub`.
        $this->wallets->preview($network, $xpub);

        $targets = [$network];

        // One EVM xpub covers both chains (SPEC §3); the indexes stay separate.
        if ($request->boolean('apply_to_evm') && NetworkCode::isEvmCode($network)) {
            $targets = [NetworkCode::Ethereum->value, NetworkCode::Bsc->value];
        }

        $user = $request->user();
        $issuedBefore = $this->addressCounts();

        // Re-saving the key that is already stored (an operator changing only
        // the label, or clicking Save twice) changes nothing about where the
        // money goes, so the "addresses were issued from the previous key"
        // warning would be a false alarm — and the one place that warning has
        // to be believed is when it is real.
        $unchanged = collect($targets)->every(
            fn (string $code) => $this->wallets->wallet($code)->xpub === $xpub
        );

        $this->wallets->store($targets, $xpub, $label, $user instanceof User ? $user : null);

        $this->audit->log('wallet.xpub_updated', null, [
            'networks' => $targets,
            // Masked, deliberately: the audit trail is a database table like
            // any other and must not become where the full key ends up.
            'xpub_masked' => Wallet::mask($xpub),
            'label' => $label,
        ]);

        $reissued = $unchanged
            ? 0
            : array_sum(array_map(fn (string $code) => $issuedBefore[$code] ?? 0, $targets));

        $item = $this->item($model, $this->addressCounts(), $this->receivedByNetwork());

        if ($reissued > 0) {
            $item['warning'] = $reissued.' deposit address(es) had already been issued from the previous key on '
                .implode(' and ', $targets).'. They still belong to the old wallet and are still monitored, so funds '
                .'already sent to them are unaffected — but they will not appear in the new wallet. '
                .'The derivation index continues from where it was and is never reset.';
        }

        return response()->json(['data' => $item]);
    }

    /** DELETE /api/admin/wallets/{network}/xpub — fall back to the watcher env key. */
    public function destroyXpub(string $network): JsonResponse
    {
        $model = $this->networkOrFail($network);

        $wallet = $this->wallets->wallet($network);
        $masked = $wallet->maskedXpub();

        $this->wallets->clear($network);

        $this->audit->log('wallet.xpub_removed', null, [
            'networks' => [$network],
            'xpub_masked' => $masked,
        ]);

        return response()->json([
            'data' => $this->item($model, $this->addressCounts(), $this->receivedByNetwork()),
        ]);
    }

    /** GET /api/admin/wallets/{network}/addresses — every address ever issued. */
    public function addresses(Request $request, string $network): JsonResponse
    {
        $model = $this->networkOrFail($network);

        $addresses = DepositAddress::query()
            ->where('network_code', $network)
            ->whereNotNull('derivation_index')
            ->with(['merchant:id,name', 'invoice:id,external_id,status'])
            ->orderByDesc('derivation_index')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        $received = $this->receivedByAddress(
            $addresses->getCollection()->pluck('id')->all()
        );

        $items = $addresses->getCollection()->map(fn (DepositAddress $address) => [
            'id' => $address->id,
            'address' => $address->address,
            'derivation_index' => $address->derivation_index,
            'network' => $address->network_code,
            'invoice_id' => $address->invoice_id,
            'merchant' => $address->merchant ? [
                'id' => $address->merchant->id,
                'name' => $address->merchant->name,
            ] : null,
            'received' => $received[$address->id] ?? $this->emptyTotals(),
            'explorer_url' => $model->explorerAddressUrl($address->address),
            'is_active' => $address->is_active,
            'created_at' => $address->created_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $addresses->currentPage(),
                'last_page' => $addresses->lastPage(),
                'per_page' => $addresses->perPage(),
                'total' => $addresses->total(),
                'from' => $addresses->firstItem(),
                'to' => $addresses->lastItem(),
            ],
        ]);
    }

    /**
     * One wallet item (SPEC §6.4). Built per network rather than as a resource
     * because most of it is aggregates, not columns.
     *
     * The two aggregate maps are passed in rather than memoised on `$this`:
     * Laravel caches the resolved controller on the Route object, so an
     * instance property would survive into the next request that hits the same
     * route (harmless in production, wrong under Octane and in feature tests).
     *
     * @param  array<string, int>  $counts
     * @param  array<string, array<string, string>>  $received
     * @return array<string, mixed>
     */
    private function item(Network $network, array $counts, array $received): array
    {
        $wallet = $this->wallets->wallet($network->code);
        $wallet->loadMissing('setBy:id,name');

        $last = DepositAddress::query()
            ->where('network_code', $network->code)
            ->whereNotNull('derivation_index')
            ->orderByDesc('derivation_index')
            ->first();

        $source = $this->wallets->source($network->code);

        return [
            'network' => $network->code,
            'network_name' => $network->name,
            'standard' => NetworkCode::standardFor($network->code),
            'source' => $source,
            'configured' => $source !== 'none',
            // Static receiving addresses on this network (Admin → Addresses);
            // they are tried before the xpub whenever an invoice needs an address.
            'receiving_addresses' => $this->receivingCounts()[$network->code] ?? 0,
            'xpub_masked' => $wallet->maskedXpub(),
            'derivation_path' => $wallet->derivation_path ?: Wallet::defaultPathFor($network->code),
            'label' => $wallet->label,
            'xpub_set_at' => $wallet->xpub_set_at?->toIso8601String(),
            'xpub_set_by' => $wallet->setBy ? ['id' => $wallet->setBy->id, 'name' => $wallet->setBy->name] : null,
            'next_index' => (int) $wallet->next_index,
            'addresses_issued' => $counts[$network->code] ?? 0,
            'last_address' => $last ? [
                'address' => $last->address,
                'derivation_index' => $last->derivation_index,
                'explorer_url' => $network->explorerAddressUrl($last->address),
                'created_at' => $last->created_at?->toIso8601String(),
            ] : null,
            'received' => $received[$network->code] ?? $this->emptyTotals(),
            'explorer_address_url' => $network->explorer_address_url,
        ];
    }

    /** @return list<Network> */
    private function networks(): array
    {
        $order = array_flip(array_column(NetworkCode::cases(), 'value'));

        return Network::query()
            ->get()
            ->sortBy(fn (Network $network) => $order[$network->code] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    private function networkOrFail(string $code): Network
    {
        $network = Network::query()->where('code', $code)->first();

        if (! $network || NetworkCode::tryFrom($code) === null) {
            throw new NotFoundHttpException('Unknown network.');
        }

        return $network;
    }

    /**
     * Enabled static receiving addresses per network.
     *
     * @return array<string, int>
     */
    private function receivingCounts(): array
    {
        return ReceivingAddress::query()
            ->where('is_enabled', true)
            ->selectRaw('network_code, count(*) as aggregate')
            ->groupBy('network_code')
            ->pluck('aggregate', 'network_code')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * HD deposit addresses issued per network (pooled ones are not "issued"
     * from the key and are listed on the Addresses page instead).
     *
     * @return array<string, int>
     */
    private function addressCounts(): array
    {
        return DepositAddress::query()
            ->whereNotNull('derivation_index')
            ->selectRaw('network_code, count(*) as aggregate')
            ->groupBy('network_code')
            ->pluck('aggregate', 'network_code')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * What the wallet has actually received, per network and currency: the sum
     * of the ledger across every merchant — the same numbers the balances are
     * built from, so the two can never disagree.
     *
     * Deliberately *not* filtered to `deposit` entries: a reorg writes a
     * negative `adjustment` that undoes a credit (TransactionIngestService),
     * and counting only deposits would keep reporting money that was taken
     * back. Summing the whole ledger reports what was credited and stuck.
     *
     * @return array<string, array<string, string>>
     */
    private function receivedByNetwork(): array
    {
        $totals = [];

        LedgerEntry::query()
            ->get(['network_code', 'currency', 'amount'])
            ->each(function (LedgerEntry $entry) use (&$totals) {
                $network = (string) $entry->network_code;
                $currency = (string) $entry->currency;
                $totals[$network][$currency] = Money::add(
                    $totals[$network][$currency] ?? '0',
                    $entry->amount,
                );
            });

        return array_map(
            fn (array $byCurrency) => $this->formatTotals($byCurrency),
            $totals,
        );
    }

    /**
     * Confirmed transfers to each of the given deposit addresses, per currency.
     *
     * @param  list<string>  $addressIds
     * @return array<string, array<string, string>>
     */
    private function receivedByAddress(array $addressIds): array
    {
        if ($addressIds === []) {
            return [];
        }

        $totals = [];

        Transaction::query()
            ->whereIn('deposit_address_id', $addressIds)
            ->where('status', TransactionStatus::Confirmed->value)
            ->get(['deposit_address_id', 'currency', 'amount'])
            ->each(function (Transaction $tx) use (&$totals) {
                $id = (string) $tx->deposit_address_id;
                $currency = (string) $tx->currency;
                $totals[$id][$currency] = Money::add($totals[$id][$currency] ?? '0', $tx->amount);
            });

        return array_map(fn (array $byCurrency) => $this->formatTotals($byCurrency), $totals);
    }

    /**
     * @param  array<string, string>  $byCurrency
     * @return array<string, string>
     */
    private function formatTotals(array $byCurrency): array
    {
        $out = $this->emptyTotals();

        foreach ($byCurrency as $currency => $amount) {
            $out[$currency] = Money::trim($amount, 2);
        }

        return $out;
    }

    /** Every currency, so the UI always has the same rows to render. */
    /** @return array<string, string> */
    private function emptyTotals(): array
    {
        return array_fill_keys(array_column(Currency::cases(), 'value'), '0.00');
    }
}
