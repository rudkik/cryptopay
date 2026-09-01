<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    use ResolvesMerchant;

    public function index(Request $request): AnonymousResourceCollection
    {
        $merchant = $this->merchant($request);

        $filters = $request->validate([
            'invoice_id' => ['nullable', 'uuid'],
            'network' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(array_column(TransactionStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = Transaction::query()
            ->where('merchant_id', $merchant->id)
            ->when($filters['invoice_id'] ?? null, fn ($q, $v) => $q->where('invoice_id', $v))
            ->when($filters['network'] ?? null, fn ($q, $v) => $q->where('network_code', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate($this->perPage($request));

        return TransactionResource::collection($transactions);
    }
}
