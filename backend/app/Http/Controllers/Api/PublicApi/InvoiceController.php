<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectInvoiceNetworkRequest;
use App\Http\Resources\PublicInvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function show(string $invoice): PublicInvoiceResource
    {
        $model = Invoice::query()
            ->with(['depositAddress', 'transactions', 'tokenPurchase.token'])
            ->whereKey($invoice)
            ->firstOrFail();

        return new PublicInvoiceResource($model);
    }

    /**
     * The payer picks a currency and network on the hosted checkout (SPEC §6.3),
     * which is what allocates the deposit address.
     *
     * Unauthenticated by design — holding the payment link is the credential —
     * so the exposure is kept flat: the route id is uuid-constrained, the body
     * is two enum values, the work is a fixed handful of queries plus one
     * watcher call whatever the input, and it shares the 120/min public
     * limiter with the checkout's poll. It leaks nothing the GET on the same
     * id does not already show, and it can succeed at most once per invoice —
     * every later call is a 409.
     */
    public function select(SelectInvoiceNetworkRequest $request, string $invoice): PublicInvoiceResource
    {
        $model = Invoice::query()->whereKey($invoice)->firstOrFail();

        $selected = $this->invoices->selectNetwork(
            $model,
            (string) $request->input('currency'),
            (string) $request->input('network'),
        );

        return new PublicInvoiceResource(
            $selected->load(['depositAddress', 'transactions', 'tokenPurchase.token'])
        );
    }
}
