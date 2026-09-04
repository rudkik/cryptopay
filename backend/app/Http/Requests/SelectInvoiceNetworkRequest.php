<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\NetworkCode;
use App\Support\NetworkRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body of `POST /api/public/invoices/{id}/select` and its merchant-side twin
 * `POST /api/v1/invoices/{id}/select` (SPEC §6.1, §6.3).
 *
 * The public route is reachable by anyone holding a payment link, so the input
 * is deliberately tiny and closed: two enum values and nothing else.
 */
class SelectInvoiceNetworkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::in(array_column(Currency::cases(), 'value'))],
            'network' => ['required', Rule::in(array_column(NetworkCode::cases(), 'value'))],
        ];
    }

    /**
     * The rules above only say the pair is spellable. This says it is actually
     * on offer right now — the same enabled-network × enabled-contract set the
     * public invoice advertises in `options`, so the checkout can never submit
     * a combination it was not shown.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $networkCode = (string) $this->input('network');
            $currency = (string) $this->input('currency');

            $registry = NetworkRegistry::make();
            $network = $registry->network($networkCode);
            $contract = $registry->contract($networkCode, $currency);

            if (! $network?->is_enabled || ! $contract?->is_enabled) {
                $validator->errors()->add('network', "{$currency} is not available on [{$networkCode}].");
            }
        }];
    }
}
