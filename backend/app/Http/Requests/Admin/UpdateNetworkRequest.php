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
            // http(s) only. These are templates ("https://tronscan.org/#/transaction/{hash}"),
            // so Laravel's `url` rule would reject the `{hash}` placeholder — hence a
            // scheme check rather than full URL validation. Without it an admin could
            // store `javascript:...`, and `explorer_address_url` is echoed verbatim into
            // the UNAUTHENTICATED public invoice payload (SPEC §6.3). SECURITY.md §4.1
            // records this fix for success_url/cancel_url/webhook_url/image_url; the two
            // explorer templates were missed.
            'explorer_tx_url' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:#^https?://#i'],
            'explorer_address_url' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:#^https?://#i'],
            'name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'explorer_tx_url.regex' => 'The explorer tx url must start with http:// or https://.',
            'explorer_address_url.regex' => 'The explorer address url must start with http:// or https://.',
        ];
    }
}
