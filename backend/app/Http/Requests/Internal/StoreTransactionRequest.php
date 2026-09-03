<?php

namespace App\Http\Requests\Internal;

use App\Enums\NetworkCode;
use App\Enums\TransactionStatus;
use App\Support\Money;
use App\Support\NetworkRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything that turns into merchant money enters through this payload, so it
 * is validated hard rather than trusted because the shared internal token
 * matched. A compromised or buggy watcher must not be able to mint a credit
 * that has no on-chain counterpart.
 */
class StoreTransactionRequest extends FormRequest
{
    /** A transaction can never plausibly have more confirmations than this. */
    public const MAX_CONFIRMATIONS = 10_000_000;

    /** Guards against a `raw` blob being used to bloat the transactions table. */
    public const MAX_RAW_BYTES = 16384;

    public static function transactionRules(string $prefix = ''): array
    {
        return [
            $prefix.'network' => ['required', Rule::in(array_column(NetworkCode::cases(), 'value'))],
            $prefix.'tx_hash' => ['required', 'string', 'max:255'],
            $prefix.'log_index' => ['nullable', 'integer', 'min:0', 'max:100000'],
            $prefix.'contract_address' => ['nullable', 'string', 'max:255'],
            $prefix.'symbol' => ['required', 'string', 'max:16'],
            $prefix.'from_address' => ['nullable', 'string', 'max:255'],
            $prefix.'to_address' => ['required', 'string', 'max:255'],
            $prefix.'amount_raw' => ['nullable', 'string', 'regex:/^\d{1,78}$/'],
            $prefix.'amount' => ['nullable', 'regex:/^\d{1,30}(\.\d{1,18})?$/'],
            $prefix.'block_number' => ['nullable', 'integer', 'min:0', 'max:9223372036854775807'],
            $prefix.'block_hash' => ['nullable', 'string', 'max:255'],
            $prefix.'confirmations' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_CONFIRMATIONS],
            $prefix.'status' => ['required', Rule::in(array_column(TransactionStatus::cases(), 'value'))],
            $prefix.'raw' => ['nullable', 'array'],
        ];
    }

    public function rules(): array
    {
        return array_merge(self::transactionRules(), [
            'amount' => ['required_without:amount_raw', 'nullable', 'regex:/^\d{1,30}(\.\d{1,18})?$/'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            self::checkTransaction($validator, $validator->getData(), '');
        });
    }

    /**
     * Semantic checks that structural rules cannot express. Shared with the
     * batch endpoint, which calls it once per item.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function checkTransaction(Validator $validator, array $payload, string $prefix): void
    {
        // Structural validation already failed for this item; re-reporting the
        // same fields as semantic errors only adds noise.
        if ($validator->errors()->hasAny(array_map(
            fn (string $field) => $prefix.$field,
            ['network', 'symbol', 'amount', 'amount_raw', 'tx_hash', 'status'],
        ))) {
            return;
        }

        $network = (string) ($payload['network'] ?? '');
        $symbol = (string) ($payload['symbol'] ?? '');

        self::checkTxHash($validator, $network, (string) ($payload['tx_hash'] ?? ''), $prefix);

        $contract = NetworkRegistry::make()->contract($network, $symbol);

        if (! $contract || ! $contract->is_enabled) {
            $validator->errors()->add(
                $prefix.'symbol',
                "[{$symbol}] is not an enabled token on [{$network}].",
            );

            return;
        }

        $hasAmount = isset($payload['amount']) && $payload['amount'] !== null && $payload['amount'] !== '';
        $hasRaw = isset($payload['amount_raw']) && $payload['amount_raw'] !== null && $payload['amount_raw'] !== '';

        // A zero or missing amount can never credit anything; reject it rather
        // than persisting a row that silently does nothing.
        $amount = $hasAmount
            ? Money::normalize($payload['amount'])
            : ($hasRaw ? Money::fromBaseUnits($payload['amount_raw'], $contract->decimals) : Money::zero());

        if (! Money::isPositive($amount)) {
            $validator->errors()->add($prefix.'amount', 'The amount must be greater than zero.');

            return;
        }

        // The watcher always sends both. When it does, they must agree at the
        // contract's decimals — a mismatch means the two sides disagree about
        // the token, which is exactly how a credit ends up 10^12 times too big.
        if ($hasAmount && $hasRaw) {
            $expected = Money::toBaseUnits($amount, $contract->decimals);

            if (bccomp($expected, (string) $payload['amount_raw'], 0) !== 0) {
                $validator->errors()->add(
                    $prefix.'amount_raw',
                    "amount and amount_raw disagree: {$payload['amount']} at {$contract->decimals} decimals is {$expected}.",
                );
            }
        }

        if (isset($payload['raw']) && is_array($payload['raw'])) {
            $encoded = json_encode($payload['raw']);

            if ($encoded === false || mb_strlen($encoded, '8bit') > self::MAX_RAW_BYTES) {
                $validator->errors()->add($prefix.'raw', 'The raw payload is too large.');
            }
        }
    }

    /** EVM hashes are `0x` + 64 hex; Tron hashes are bare 64 hex. */
    private static function checkTxHash(Validator $validator, string $network, string $hash, string $prefix): void
    {
        $pattern = NetworkCode::isEvmCode($network)
            ? '/^0x[0-9a-fA-F]{64}$/'
            : '/^[0-9a-fA-F]{64}$/';

        if (! preg_match($pattern, $hash)) {
            $validator->errors()->add(
                $prefix.'tx_hash',
                "The tx_hash is not a valid [{$network}] transaction hash.",
            );
        }
    }
}
