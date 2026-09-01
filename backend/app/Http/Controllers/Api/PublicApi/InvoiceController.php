<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicInvoiceResource;
use App\Models\Invoice;

class InvoiceController extends Controller
{
    public function show(string $invoice): PublicInvoiceResource
    {
        $model = Invoice::query()
            ->with(['depositAddress', 'transactions', 'tokenPurchase.token'])
            ->whereKey($invoice)
            ->firstOrFail();

        return new PublicInvoiceResource($model);
    }
}
