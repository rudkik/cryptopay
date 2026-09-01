<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTokenRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'symbol' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price_usd' => ['sometimes', 'regex:/^\d{1,18}(\.\d{1,18})?$/', 'not_in:0,0.0,0.00'],
            'decimals' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'total_supply' => ['sometimes', 'nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/'],
            'min_purchase' => ['sometimes', 'nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/'],
            'max_purchase' => ['sometimes', 'nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/'],
            'is_active' => ['sometimes', 'boolean'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2000'],
        ];
    }
}
