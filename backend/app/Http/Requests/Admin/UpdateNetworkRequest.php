<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNetworkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'confirmations_required' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'explorer_tx_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'explorer_address_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
