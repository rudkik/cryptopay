<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BalanceResource;
use App\Http\Resources\LedgerEntryResource;
use App\Models\Balance;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BalanceController extends Controller
{
    /**
     * `merchant_id` is a uuid column: handed anything else, Postgres raises
     * 22P02 and the request became a 500 whose message carried the whole SQL
     * statement plus the database host, port and name. Validating it is both
     * the correct 422 and what stops the leak; every sibling list endpoint
     * already does this, these two were the outliers.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $extra = []): array
    {
        return $request->validate($extra + [
            'merchant_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request);

        $balances = Balance::query()
            ->when($filters['merchant_id'] ?? null, fn ($q, $v) => $q->where('merchant_id', $v))
            ->orderBy('merchant_id')
            ->orderBy('currency')
            ->get();

        return BalanceResource::collection($balances);
    }

    public function ledger(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request, [
            'currency' => ['nullable', 'string', 'max:16'],
            'network' => ['nullable', 'string', 'max:32'],
        ]);

        $entries = LedgerEntry::query()
            ->when($filters['merchant_id'] ?? null, fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v))
            ->when($filters['network'] ?? null, fn ($q, $v) => $q->where('network_code', $v))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 50))));

        return LedgerEntryResource::collection($entries);
    }
}
