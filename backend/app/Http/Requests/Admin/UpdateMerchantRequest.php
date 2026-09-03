<?php

namespace App\Http\Requests\Admin;

use App\Rules\WebhookUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'webhook_url' => ['nullable', 'url:http,https', 'max:2000', new WebhookUrl],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.underpayment_tolerance' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
