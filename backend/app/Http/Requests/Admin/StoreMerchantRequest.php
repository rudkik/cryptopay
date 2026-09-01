<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.underpayment_tolerance' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
