<?php

namespace App\Http\Requests\Internal;

use App\Enums\NetworkCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HeartbeatRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'network' => ['required', Rule::in(array_column(NetworkCode::cases(), 'value'))],
            'last_scanned_block' => ['nullable', 'integer'],
            'head_block' => ['nullable', 'integer'],
            'healthy' => ['nullable', 'boolean'],
            'error' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
