<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TokenPurchaseResource;
use App\Models\TokenPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TokenPurchaseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $purchases = TokenPurchase::query()
            ->with(['token', 'invoice'])
            ->when($request->input('merchant_id'), fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($request->input('token_id'), fn ($q, $v) => $q->where('token_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
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
