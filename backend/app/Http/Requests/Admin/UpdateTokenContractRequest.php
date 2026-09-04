<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTokenContractRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contract_address' => ['sometimes', 'string', 'max:255', $this->contractFormatRule()],
            'decimals' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A typo here silently stops every deposit for the token (the watcher
     * matches Transfer logs against this exact value), so the shape is
     * enforced per network family: EVM 0x + 40 hex, Tron base58 T-address.
     */
    private function contractFormatRule(): \Closure
    {
        $network = (string) ($this->route('network') ?? $this->route('code') ?? '');
        $pattern = $network === 'tron'
            ? '/^T[1-9A-HJ-NP-Za-km-z]{33}$/'
            : '/^0x[0-9a-fA-F]{40}$/';
        $hint = $network === 'tron' ? 'a Tron base58 address (T…, 34 chars)' : 'an EVM address (0x + 40 hex chars)';

        return function (string $attribute, mixed $value, \Closure $fail) use ($pattern, $hint): void {
            if (! is_string($value) || ! preg_match($pattern, $value)) {
                $fail("The contract address must be {$hint}.");
            }
        };
    }
}
