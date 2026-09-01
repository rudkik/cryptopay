<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreTokenPurchaseRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TokenPurchaseResource;
use App\Models\Token;
use App\Models\TokenPurchase;
use App\Services\TokenPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TokenPurchaseController extends Controller
{
    use ResolvesMerchant;

    public function __construct(private readonly TokenPurchaseService $purchases) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $merchant = $this->merchant($request);

        $filters = $request->validate([
            'customer_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'token_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $purchases = TokenPurchase::query()
            ->where('merchant_id', $merchant->id)
            ->with('token')
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['token_id'] ?? null, fn ($q, $v) => $q->where('token_id', $v))
            ->latest()
            ->paginate($this->perPage($request));

        return TokenPurchaseResource::collection($purchases);
    }

    public function store(StoreTokenPurchaseRequest $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validated();

        $token = Token::query()
            ->where('merchant_id', $merchant->id)
            ->whereKey($data['token_id'])
            ->firstOrFail();

        ['purchase' => $purchase, 'invoice' => $invoice] = $this->purchases->create($merchant, $token, $data);

        return response()->json([
            'purchase' => (new TokenPurchaseResource($purchase))->toArray($request),
            'invoice' => (new InvoiceResource($invoice->load(['depositAddress', 'transactions'])))->toArray($request),
        ], 201);
    }

    public function show(Request $request, string $purchase): TokenPurchaseResource
    {
        $merchant = $this->merchant($request);

        $model = TokenPurchase::query()
            ->where('merchant_id', $merchant->id)
            ->with(['token', 'invoice'])
            ->whereKey($purchase)
            ->firstOrFail();

        return new TokenPurchaseResource($model);
    }
}
