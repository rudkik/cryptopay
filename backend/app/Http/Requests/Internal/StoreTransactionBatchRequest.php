<?php

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionBatchRequest extends FormRequest
{
    /** Matches the watcher's own chunk size (src/backend/client.ts). */
    public const MAX_ITEMS = 200;

    public function rules(): array
    {
        return array_merge([
            'transactions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
        ], StoreTransactionRequest::transactionRules('transactions.*.'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $items = $validator->getData()['transactions'] ?? [];

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $payload) {
                if (is_array($payload)) {
                    StoreTransactionRequest::checkTransaction($validator, $payload, "transactions.{$index}.");
                }
            }
        });
    }
}
