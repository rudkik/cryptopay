<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminTransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'invoice_id' => ['nullable', 'uuid'],
            'merchant_id' => ['nullable', 'uuid'],
            'network' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(array_column(TransactionStatus::cases(), 'value'))],
            'q' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = Transaction::query()
            ->with('merchant')
            ->when($filters['invoice_id'] ?? null, fn ($q, $v) => $q->where('invoice_id', $v))
            ->when($filters['merchant_id'] ?? null, fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($filters['network'] ?? null, fn ($q, $v) => $q->where('network_code', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('tx_hash', 'like', "%{$v}%")
                ->orWhere('to_address', 'like', "%{$v}%")))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return AdminTransactionResource::collection($transactions);
    }

    public function show(string $transaction): AdminTransactionResource
    {
        return new AdminTransactionResource(
            Transaction::query()->with('merchant')->whereKey($transaction)->firstOrFail()
        );
    }
}
