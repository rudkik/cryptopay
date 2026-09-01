<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTokenRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'uuid', 'exists:merchants,id'],
            'symbol' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price_usd' => ['required', 'regex:/^\d{1,18}(\.\d{1,18})?$/', 'not_in:0,0.0,0.00'],
            'decimals' => ['nullable', 'integer', 'min:0', 'max:36'],
            'total_supply' => ['nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/'],
            'min_purchase' => ['nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/'],
            'max_purchase' => ['nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/'],
            'is_active' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'url', 'max:2000'],
        ];
    }
}
