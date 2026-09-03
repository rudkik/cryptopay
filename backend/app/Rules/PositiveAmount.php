<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bounds a decimal money string with bcmath rather than float comparison, so
 * `"0.0000000000000000001"` and `"1e400"` are both handled exactly.
 */
class PositiveAmount implements ValidationRule
{
    public function __construct(
        private readonly string $min = '0',
        private readonly ?string $max = null,
        private readonly bool $exclusiveMin = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $amount = Money::normalize($value);

        if (Money::cmp($amount, '0') <= 0) {
            $fail('The :attribute must be greater than zero.');

            return;
        }

        $comparison = Money::cmp($amount, $this->min);

        if ($this->exclusiveMin ? $comparison < 0 : $comparison <= 0) {
            $fail('The :attribute must be at least '.Money::trim($this->min, 0).'.');

            return;
        }

        if ($this->max !== null && Money::cmp($amount, $this->max) > 0) {
            $fail('The :attribute must not be greater than '.Money::trim($this->max, 0).'.');
        }
    }
}
