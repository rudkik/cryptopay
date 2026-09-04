<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `PUT /api/admin/wallets/{network}` and
 * `POST /api/admin/wallets/{network}/preview` (SPEC §6.4).
 *
 * Only the cheap shape checks live here — that it is a plausible mainnet
 * extended *public* key of a sane length. Whether it actually decodes, carries
 * a valid checksum and sits at account level is decided by the watcher, which
 * is the only service that owns BIP32.
 */
class StoreWalletXpubRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `xprv` would be a private key: rejecting the prefix outright
            // means a mis-paste is refused before it is sent anywhere.
            'xpub' => ['required', 'string', 'min:64', 'max:200', 'regex:/^xpub[1-9A-HJ-NP-Za-km-z]+$/'],
            'label' => ['nullable', 'string', 'max:120'],
            'apply_to_evm' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'xpub.regex' => 'Enter a mainnet account-level extended public key: it starts with "xpub" and is base58. Never paste a private key (xprv) or a seed phrase.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('xpub'))) {
            // Wallet apps and QR readers routinely add whitespace/newlines.
            $this->merge(['xpub' => preg_replace('/\s+/', '', $this->input('xpub'))]);
        }

        if (is_string($this->input('label'))) {
            $this->merge(['label' => trim($this->input('label'))]);
        }
    }
}
