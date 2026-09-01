<?php

namespace App\Http\Requests\V1;

use App\Enums\Currency;
use App\Enums\NetworkCode;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d{1,18}(\.\d{1,18})?$/', 'not_in:0,0.0,0.00'],
            'currency' => ['required', Rule::in(array_column(Currency::cases(), 'value'))],
            'network' => ['required', Rule::in(array_column(NetworkCode::cases(), 'value'))],
            'external_id' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'success_url' => ['nullable', 'url', 'max:2000'],
            'cancel_url' => ['nullable', 'url', 'max:2000'],
            'expires_in' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'The amount must be a positive decimal string, for example "100.5".',
            'amount.not_in' => 'The amount must be greater than zero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('amount') && is_numeric($this->input('amount'))) {
            $this->merge(['amount' => Money::trim($this->input('amount'), 0)]);
        }
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();
        $data['expires_in'] ??= 3600;

        return $data;
    }
}
