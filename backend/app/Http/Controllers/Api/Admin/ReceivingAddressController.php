<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Currency;
use App\Enums\NetworkCode;
use App\Enums\TransactionStatus;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReceivingAddressRequest;
use App\Models\Invoice;
use App\Models\Network;
use App\Models\ReceivingAddress;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The operator's list of static receiving addresses — the first place
 * AddressService looks when an invoice needs somewhere to be paid to.
 *
 * Reads are open to viewers; every mutation is behind role=admin and audited.
 */
class ReceivingAddressController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/admin/receiving-addresses?network= */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'network' => ['nullable', Rule::in(array_column(NetworkCode::cases(), 'value'))],
        ]);

        $order = array_flip(array_column(NetworkCode::cases(), 'value'));

        $rows = ReceivingAddress::query()
            ->when($filters['network'] ?? null, fn ($q, $v) => $q->where('network_code', $v))
            ->with(['depositAddress.invoice:id,external_id,status,merchant_id,expires_at', 'depositAddress.merchant:id,name'])
            ->get()
            ->sortBy([
                fn (ReceivingAddress $a, ReceivingAddress $b) => ($order[$a->network_code] ?? 99) <=> ($order[$b->network_code] ?? 99),
                fn (ReceivingAddress $a, ReceivingAddress $b) => $a->priority <=> $b->priority,
                fn (ReceivingAddress $a, ReceivingAddress $b) => $a->created_at <=> $b->created_at,
            ])
            ->values();

        $networks = Network::query()->get()->keyBy('code');
        $received = $this->receivedByLease($rows->pluck('depositAddress.id')->filter()->all());
        $invoices = $this->invoiceCounts($rows->pluck('depositAddress.id')->filter()->all());

        return response()->json(['data' => $rows->map(
            fn (ReceivingAddress $row) => $this->item($row, $networks->get($row->network_code), $received, $invoices),
        )->all()]);
    }

    /** POST /api/admin/receiving-addresses */
    public function store(StoreReceivingAddressRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $row = ReceivingAddress::create([
            'network_code' => $data['network'],
            'address' => $data['address'],
            'currencies' => array_values($data['currencies'] ?? []),
            'label' => $data['label'] ?? null,
            'priority' => $data['priority'] ?? 100,
            'is_enabled' => $data['is_enabled'] ?? true,
            'created_by' => $user instanceof User ? $user->getKey() : null,
        ]);

        $this->wallets->forget($row->network_code);

        $this->audit->log('receiving_address.created', $row, [
            'network' => $row->network_code,
            'address' => $row->address,
            'currencies' => $row->currencyList(),
            'label' => $row->label,
            'priority' => $row->priority,
            'is_enabled' => $row->is_enabled,
        ]);

        return response()->json(['data' => $this->show($row)->getData(true)['data']], 201);
    }

    /** GET /api/admin/receiving-addresses/{receivingAddress} */
    public function show(ReceivingAddress $receivingAddress): JsonResponse
    {
        $receivingAddress->load(['depositAddress.invoice:id,external_id,status,merchant_id,expires_at', 'depositAddress.merchant:id,name']);

        $leaseIds = array_filter([$receivingAddress->depositAddress?->id]);

        return response()->json(['data' => $this->item(
            $receivingAddress,
            Network::query()->where('code', $receivingAddress->network_code)->first(),
            $this->receivedByLease($leaseIds),
            $this->invoiceCounts($leaseIds),
        )]);
    }

    /** PUT /api/admin/receiving-addresses/{receivingAddress} */
    public function update(StoreReceivingAddressRequest $request, ReceivingAddress $receivingAddress): JsonResponse
    {
        $data = $request->validated();
        $before = $receivingAddress->only(['currencies', 'label', 'priority', 'is_enabled']);

        $receivingAddress->fill([
            'currencies' => array_key_exists('currencies', $data)
                ? array_values($data['currencies'])
                : $receivingAddress->currencies,
            'label' => array_key_exists('label', $data) ? $data['label'] : $receivingAddress->label,
            'priority' => $data['priority'] ?? $receivingAddress->priority,
            'is_enabled' => $data['is_enabled'] ?? $receivingAddress->is_enabled,
        ])->save();

        $this->wallets->forget($receivingAddress->network_code);

        $this->audit->log('receiving_address.updated', $receivingAddress, [
            'address' => $receivingAddress->address,
            'from' => $before,
            'to' => $receivingAddress->only(['currencies', 'label', 'priority', 'is_enabled']),
        ]);

        return $this->show($receivingAddress);
    }

    /**
     * DELETE /api/admin/receiving-addresses/{receivingAddress}
     *
     * Refused while an invoice holds the lease. The `deposit_addresses` row
     * survives the delete (FK set null): its history stays attached to the
     * invoices that used it, and it stays watched, so a late payment is still
     * recorded rather than lost.
     */
    public function destroy(ReceivingAddress $receivingAddress): JsonResponse
    {
        $lease = $receivingAddress->depositAddress;

        if ($lease && $lease->isLeased()) {
            throw new InvalidStateException(
                'This address is leased to an open invoice until '.$lease->leased_until?->toIso8601String()
                    .'. Disable it instead so no new invoice gets it, and delete it once the lease ends.',
                ['leased_until' => [$lease->leased_until?->toIso8601String()]],
            );
        }

        $snapshot = [
            'network' => $receivingAddress->network_code,
            'address' => $receivingAddress->address,
            'currencies' => $receivingAddress->currencyList(),
            'label' => $receivingAddress->label,
        ];

        $receivingAddress->delete();
        $this->wallets->forget($snapshot['network']);

        $this->audit->log('receiving_address.deleted', null, $snapshot);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @param  array<string, array<string, string>>  $received  keyed by deposit_addresses.id
     * @param  array<string, int>  $invoices  keyed by deposit_addresses.id
     * @return array<string, mixed>
     */
    private function item(ReceivingAddress $row, ?Network $network, array $received, array $invoices): array
    {
        $lease = $row->depositAddress;
        $busy = $lease?->isLeased() ?? false;
        $invoice = $lease?->invoice;

        return [
            'id' => $row->id,
            'network' => $row->network_code,
            'network_name' => $network?->name ?? $row->network_code,
            'standard' => NetworkCode::standardFor($row->network_code),
            'address' => $row->address,
            // [] = every currency enabled on the network.
            'currencies' => $row->currencyList(),
            'label' => $row->label,
            'priority' => (int) $row->priority,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => ! $row->is_enabled ? 'disabled' : ($busy ? 'busy' : 'free'),
            'lease' => $lease ? [
                'leased_until' => $lease->leased_until?->toIso8601String(),
                'active' => $busy,
                'invoice' => $invoice instanceof Invoice ? [
                    'id' => $invoice->id,
                    'external_id' => $invoice->external_id,
                    'status' => $invoice->status->value,
                    'expires_at' => $invoice->expires_at?->toIso8601String(),
                ] : null,
                'merchant' => $lease->merchant ? ['id' => $lease->merchant->id, 'name' => $lease->merchant->name] : null,
            ] : null,
            'invoices_count' => $lease ? ($invoices[$lease->id] ?? 0) : 0,
            'received' => $lease ? ($received[$lease->id] ?? $this->emptyTotals()) : $this->emptyTotals(),
            'last_leased_at' => $row->last_leased_at?->toIso8601String(),
            'explorer_url' => $network?->explorerAddressUrl($row->address),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * Confirmed transfers per lease row and currency.
     *
     * @param  list<string>  $leaseIds
     * @return array<string, array<string, string>>
     */
    private function receivedByLease(array $leaseIds): array
    {
        if ($leaseIds === []) {
            return [];
        }

        $totals = [];

        Transaction::query()
            ->whereIn('deposit_address_id', $leaseIds)
            ->where('status', TransactionStatus::Confirmed->value)
            ->get(['deposit_address_id', 'currency', 'amount'])
            ->each(function (Transaction $tx) use (&$totals) {
                $id = (string) $tx->deposit_address_id;
                $currency = (string) $tx->currency;
                $totals[$id][$currency] = Money::add($totals[$id][$currency] ?? '0', $tx->amount);
            });

        $out = [];

        foreach ($totals as $id => $byCurrency) {
            $row = $this->emptyTotals();

            foreach ($byCurrency as $currency => $amount) {
                $row[$currency] = Money::trim($amount, 2);
            }

            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * How many invoices have ever been paid to each lease row.
     *
     * @param  list<string>  $leaseIds
     * @return array<string, int>
     */
    private function invoiceCounts(array $leaseIds): array
    {
        if ($leaseIds === []) {
            return [];
        }

        return Invoice::query()
            ->whereIn('deposit_address_id', $leaseIds)
            ->selectRaw('deposit_address_id, count(*) as aggregate')
            ->groupBy('deposit_address_id')
            ->pluck('aggregate', 'deposit_address_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, string> */
    private function emptyTotals(): array
    {
        return array_fill_keys(array_column(Currency::cases(), 'value'), '0.00');
    }
}
