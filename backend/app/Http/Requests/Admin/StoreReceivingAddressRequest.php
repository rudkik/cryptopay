<?php

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use App\Enums\NetworkCode;
use App\Models\ReceivingAddress;
use App\Models\TokenContract;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of `POST /api/admin/receiving-addresses` and
 * `PUT /api/admin/receiving-addresses/{id}`.
 *
 * `network` and `address` are only accepted on create: an address that has
 * already been leased has invoices and transactions pointing at it, and
 * "editing" it to another string would silently re-route their money.
 */
class StoreReceivingAddressRequest extends FormRequest
{
    private const EVM = '/^0x[0-9a-fA-F]{40}$/';

    private const TRON = '/^T[1-9A-HJ-NP-Za-km-z]{33}$/';

    public function rules(): array
    {
        $creating = $this->route('receivingAddress') === null;

        $rules = [
            'currencies' => ['sometimes', 'array'],
            'currencies.*' => ['string', Rule::in(array_column(Currency::cases(), 'value'))],
            'label' => ['nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];

        if ($creating) {
            $rules['network'] = ['required', Rule::in(array_column(NetworkCode::cases(), 'value'))];
            $rules['address'] = ['required', 'string', 'max:64', $this->addressFormat(), $this->addressUnique()];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $network = $this->networkCode();

            if ($network === null) {
                return;
            }

            // Every listed currency has to actually exist on the network —
            // "USDC on Tron" would be accepted and then never offered.
            foreach ((array) $this->input('currencies', []) as $i => $symbol) {
                if (! is_string($symbol)) {
                    continue;
                }

                $known = TokenContract::query()
                    ->where('network_code', $network)
                    ->where('symbol', $symbol)
                    ->exists();

                if (! $known) {
                    $v->errors()->add("currencies.{$i}", "{$symbol} is not a token on [{$network}].");
                }
            }
        });
    }

    /** The network being validated: from the body on create, from the row on update. */
    public function networkCode(): ?string
    {
        $route = $this->route('receivingAddress');

        if ($route instanceof ReceivingAddress) {
            return $route->network_code;
        }

        $network = $this->input('network');

        return is_string($network) && NetworkCode::tryFrom($network) ? $network : null;
    }

    private function addressFormat(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $network = $this->networkCode();

            if ($network === null || ! is_string($value)) {
                return;
            }

            $ok = NetworkCode::isEvmCode($network)
                ? preg_match(self::EVM, $value) === 1
                : preg_match(self::TRON, $value) === 1;

            if (! $ok) {
                $fail(NetworkCode::isEvmCode($network)
                    ? 'Enter a 0x-prefixed 40-hex-character address for this network.'
                    : 'Enter a Tron base58 address: it starts with "T" and is 34 characters long.');
            }
        };
    }

    private function addressUnique(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $network = $this->networkCode();

            if ($network === null || ! is_string($value)) {
                return;
            }

            $query = ReceivingAddress::query()->where('network_code', $network);

            if (NetworkCode::isEvmCode($network)) {
                $query->whereRaw('lower(address) = ?', [mb_strtolower($value)]);
            } else {
                $query->where('address', $value);
            }

            if ($query->exists()) {
                $fail('This address is already in the list for this network.');
            }
        };
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('address'))) {
            $this->merge(['address' => preg_replace('/\s+/', '', $this->input('address'))]);
        }

        if (is_string($this->input('label'))) {
            $this->merge(['label' => trim($this->input('label'))]);
        }

        if (is_array($this->input('currencies'))) {
            $this->merge(['currencies' => array_values(array_unique(array_map(
                fn ($c) => is_string($c) ? strtoupper(trim($c)) : $c,
                $this->input('currencies'),
            )))]);
        }
    }
}
