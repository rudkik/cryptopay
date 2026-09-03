<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SimulatePaymentRequest;
use App\Http\Resources\Admin\AdminInvoiceResource;
use App\Models\Invoice;
use App\Services\AuditLogger;
use App\Services\InvoiceService;
use App\Services\PaymentSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentSimulator $simulator,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(InvoiceStatus::cases(), 'value'))],
            'external_id' => ['nullable', 'string'],
            'network' => ['nullable', 'string'],
            'currency' => ['nullable', 'string'],
            'merchant_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = Invoice::query()
            ->with(['depositAddress', 'transactions', 'merchant', 'tokenPurchase.token'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['external_id'] ?? null, fn ($q, $v) => $q->where('external_id', $v))
            ->when($filters['network'] ?? null, fn ($q, $v) => $q->where('network_code', $v))
            ->when($filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v))
            ->when($filters['merchant_id'] ?? null, fn ($q, $v) => $q->where('merchant_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            // Laravel's Postgres grammar appends ::text for LIKE, so matching a
            // uuid column with a fragment works on both drivers.
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('invoices.id', 'like', "%{$v}%")
                ->orWhere('external_id', 'like', "%{$v}%")
                ->orWhereHas('depositAddress', fn ($a) => $a->where('address', 'like', "%{$v}%"))))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return AdminInvoiceResource::collection($invoices);
    }

    public function show(string $invoice): AdminInvoiceResource
    {
        return new AdminInvoiceResource(
            Invoice::query()
                ->with([
                    'depositAddress', 'transactions', 'merchant',
                    'tokenPurchase.token', 'webhookDeliveries' => fn ($q) => $q->latest(),
                ])
                ->whereKey($invoice)
                ->firstOrFail()
        );
    }

    public function cancel(string $invoice): AdminInvoiceResource
    {
        $model = Invoice::query()->with(['depositAddress', 'merchant'])->whereKey($invoice)->firstOrFail();

        $cancelled = $this->invoices->cancel($model);

        $this->audit->log('invoice.cancelled', $cancelled, [
            'merchant_id' => $cancelled->merchant_id,
            'amount' => $cancelled->amount,
            'currency' => $cancelled->currency,
        ]);

        return new AdminInvoiceResource($cancelled);
    }

    public function simulatePayment(SimulatePaymentRequest $request, string $invoice): JsonResponse
    {
        $model = Invoice::query()->with(['depositAddress', 'merchant'])->whereKey($invoice)->firstOrFail();

        $result = $this->simulator->simulate(
            $model,
            $request->validated()['amount'] ?? null,
            $request->boolean('confirmed', true),
        );

        // Every simulation mints money that never happened on chain, so it is
        // always recorded, even though the endpoint only works outside production.
        $this->audit->log('invoice.payment_simulated', $model, [
            'merchant_id' => $model->merchant_id,
            'amount' => $result['payload']['amount'] ?? null,
            'currency' => $result['payload']['symbol'] ?? null,
            'network' => $result['payload']['network'] ?? null,
            'tx_hash' => $result['payload']['tx_hash'] ?? null,
            'status' => $result['payload']['status'] ?? null,
            'transaction_id' => $result['transaction_id'] ?? null,
        ]);

        $invoiceData = (new AdminInvoiceResource(
            $model->refresh()->load(['depositAddress', 'transactions', 'merchant', 'tokenPurchase.token'])
        ))->toArray($request);

        // `data` is the updated Invoice resource (what the admin UI reads back);
        // the simulated payload is returned alongside it for debugging.
        return response()->json([
            'data' => $invoiceData,
            'invoice' => $invoiceData,
            'ok' => true,
            'transaction_id' => $result['transaction_id'],
            'payload' => $result['payload'],
        ]);
    }
}
