<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * @mixin Transaction
 */
class AdminTransactionResource extends TransactionResource
{
    use EmbedsMerchant;

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['merchant' => $this->merchantStub()];
    }
}
