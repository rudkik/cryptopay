<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    use ResolvesMerchant;

    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $merchant = $this->merchant($request);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(InvoiceStatus::cases(), 'value'))],
            'external_id' => ['nullable', 'string'],
            'network' => ['nullable', 'string'],
            'currency' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = Invoice::query()
            ->where('merchant_id', $merchant->id)
            ->with(['depositAddress', 'transactions', 'tokenPurchase.token'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['external_id'] ?? null, fn ($q, $v) => $q->where('external_id', $v))
            ->when($filters['network'] ?? null, fn ($q, $v) => $q->where('network_code', $v))
            ->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->latest()
            ->paginate($this->perPage($request));

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        $invoice = $this->invoices->create($merchant, $request->validated());

        return (new InvoiceResource($invoice->load(['depositAddress', 'transactions'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $invoice): InvoiceResource
    {
        return new InvoiceResource($this->find($request, $invoice));
    }

    public function cancel(Request $request, string $invoice): InvoiceResource
    {
        $model = $this->find($request, $invoice);

        return new InvoiceResource($this->invoices->cancel($model));
    }

    private function find(Request $request, string $id): Invoice
    {
        $merchant = $this->merchant($request);

        return Invoice::query()
            ->where('merchant_id', $merchant->id)
            ->with(['depositAddress', 'transactions', 'tokenPurchase.token', 'merchant'])
            ->whereKey($id)
            ->firstOrFail();
    }
}
