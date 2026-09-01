<?php

namespace App\Http\Requests\Internal;

use App\Enums\NetworkCode;
use App\Enums\TransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public static function transactionRules(string $prefix = ''): array
    {
        return [
            $prefix.'network' => ['required', Rule::in(array_column(NetworkCode::cases(), 'value'))],
            $prefix.'tx_hash' => ['required', 'string', 'max:255'],
            $prefix.'log_index' => ['nullable', 'integer', 'min:0'],
            $prefix.'contract_address' => ['nullable', 'string', 'max:255'],
            $prefix.'symbol' => ['required', 'string', 'max:16'],
            $prefix.'from_address' => ['nullable', 'string', 'max:255'],
            $prefix.'to_address' => ['required', 'string', 'max:255'],
            $prefix.'amount_raw' => ['nullable', 'string', 'max:80'],
            $prefix.'amount' => ['nullable', 'regex:/^\d{1,30}(\.\d{1,18})?$/'],
            $prefix.'block_number' => ['nullable', 'integer'],
            $prefix.'block_hash' => ['nullable', 'string', 'max:255'],
            $prefix.'confirmations' => ['nullable', 'integer', 'min:0'],
            $prefix.'status' => ['required', Rule::in(array_column(TransactionStatus::cases(), 'value'))],
            $prefix.'raw' => ['nullable', 'array'],
        ];
    }

    public function rules(): array
    {
        return array_merge(self::transactionRules(), [
            'amount' => ['required_without:amount_raw', 'nullable', 'regex:/^\d{1,30}(\.\d{1,18})?$/'],
        ]);
    }
}
