<?php

namespace App\Http\Requests\Admin;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class SimulatePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'regex:/^\d{1,18}(\.\d{1,18})?$/', 'not_in:0,0.0,0.00'],
            'confirmed' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('amount') && is_numeric($this->input('amount'))) {
            $this->merge(['amount' => Money::trim($this->input('amount'), 0)]);
        }
    }
}
