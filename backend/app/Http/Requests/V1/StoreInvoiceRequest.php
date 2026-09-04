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

class StoreInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Bounded well below the decimal(36,18) column so an absurd
            // amount can never be created, and never below the smallest unit
            // a 6-decimal token can actually settle.
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,18})?$/', new PositiveAmount(min: '0.000001', max: '1000000000')],
            // Optional, but they travel together: omit both and the payer
            // picks on the hosted checkout (SPEC §6.3); sending only one is a
            // half-configured invoice, not a default, so it is rejected.
            'currency' => ['required_with:network', 'nullable', Rule::in(array_column(Currency::cases(), 'value'))],
            'network' => ['required_with:currency', 'nullable', Rule::in(array_column(NetworkCode::cases(), 'value')), new ConfiguredWallet],
            'external_id' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', new BoundedMetadata],
            // A bare `url` rule accepts `javascript:` and `data:`; the hosted
            // checkout renders these as links.
            'success_url' => ['nullable', 'url:http,https', 'max:2000'],
            'cancel_url' => ['nullable', 'url:http,https', 'max:2000'],
            'expires_in' => ['nullable', 'integer', 'min:60', 'max:86400'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'The amount must be a positive decimal string, for example "100.5".',
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
