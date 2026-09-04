<?php

namespace App\Http\Requests\V1;

use App\Enums\Currency;
use App\Enums\NetworkCode;
use App\Rules\BoundedMetadata;
use App\Rules\ConfiguredWallet;
use App\Rules\PositiveAmount;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTokenPurchaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token_id' => ['required', 'uuid'],
            'token_amount' => ['required_without:pay_amount', 'nullable', 'regex:/^\d{1,10}(\.\d{1,18})?$/', new PositiveAmount(max: '1000000000000')],
            'pay_amount' => ['required_without:token_amount', 'nullable', 'regex:/^\d{1,10}(\.\d{1,18})?$/', new PositiveAmount(min: '0.000001', max: '1000000000')],
            // Both or neither, exactly as on POST /v1/invoices.
            'currency' => ['required_with:network', 'nullable', Rule::in(array_column(Currency::cases(), 'value'))],
            'network' => ['required_with:currency', 'nullable', Rule::in(array_column(NetworkCode::cases(), 'value')), new ConfiguredWallet],
            'customer_id' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'success_url' => ['nullable', 'url:http,https', 'max:2000'],
            'cancel_url' => ['nullable', 'url:http,https', 'max:2000'],
            'metadata' => ['nullable', 'array', new BoundedMetadata],
            'expires_in' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['token_amount', 'pay_amount'] as $field) {
            if ($this->filled($field) && is_numeric($this->input($field))) {
                $this->merge([$field => Money::trim($this->input($field), 0)]);
            }
        }
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();
        $data['expires_in'] ??= 3600;

        return $data;
    }
}
