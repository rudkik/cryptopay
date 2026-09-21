<?php

namespace App\Http\Requests\Admin;

use App\Models\Merchant;
use App\Rules\WebhookUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'webhook_url' => ['nullable', 'url:http,https', 'max:2000', new WebhookUrl],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.underpayment_tolerance' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.invoice_ttl' => ['nullable', 'integer', 'min:'.Merchant::MIN_INVOICE_TTL, 'max:'.Merchant::MAX_INVOICE_TTL],
        ];
    }
}
