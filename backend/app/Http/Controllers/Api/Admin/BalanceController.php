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
    public function index(Request $request): AnonymousResourceCollection
    {
        $balances = Balance::query()
            ->when($request->input('merchant_id'), fn ($q, $v) => $q->where('merchant_id', $v))
            ->orderBy('merchant_id')
            ->orderBy('currency')
            ->get();

        return BalanceResource::collection($balances);
    }

    public function ledger(Request $request): AnonymousResourceCollection
    {
        $entries = LedgerEntry::query()
            ->when($request->input('merchant_id'), fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($request->input('currency'), fn ($q, $v) => $q->where('currency', $v))
            ->when($request->input('network'), fn ($q, $v) => $q->where('network_code', $v))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 50))));

        return LedgerEntryResource::collection($entries);
    }
}
