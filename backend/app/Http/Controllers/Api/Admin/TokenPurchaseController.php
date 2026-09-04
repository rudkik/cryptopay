<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TokenPurchaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TokenPurchaseResource;
use App\Models\TokenPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TokenPurchaseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'merchant_id' => ['nullable', 'uuid'],
            'token_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(array_column(TokenPurchaseStatus::cases(), 'value'))],
            'customer_id' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $purchases = TokenPurchase::query()
            ->with(['token', 'invoice'])
            ->when($filters['merchant_id'] ?? null, fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($filters['token_id'] ?? null, fn ($q, $v) => $q->where('token_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return TokenPurchaseResource::collection($purchases);
    }

    public function show(string $tokenPurchase): TokenPurchaseResource
    {
        return new TokenPurchaseResource(
            TokenPurchase::query()->with(['token', 'invoice'])->whereKey($tokenPurchase)->firstOrFail()
        );
    }
}
