<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionBatchRequest extends FormRequest
{
    public function rules(): array
    {
        return array_merge([
            'transactions' => ['required', 'array', 'min:1', 'max:500'],
        ], StoreTransactionRequest::transactionRules('transactions.*.'));
    }
}
