<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTokenContractRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contract_address' => ['sometimes', 'string', 'max:255'],
            'decimals' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
