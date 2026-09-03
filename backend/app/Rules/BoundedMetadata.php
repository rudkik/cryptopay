<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `metadata` is merchant-supplied JSON that is stored on the invoice and then
 * echoed back inside every webhook body. Left unbounded it is both a storage
 * amplifier and a way to make the queue worker serialise megabytes per retry,
 * so it is capped in size, key count and nesting depth.
 */
class BoundedMetadata implements ValidationRule
{
    public const MAX_BYTES = 8192;

    public const MAX_KEYS = 50;

    public const MAX_DEPTH = 5;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be an object.');

            return;
        }

        // A JSON object decodes to an associative array; a JSON array decodes to
        // a list. SPEC §6.1 says `metadata` is an object.
        if (array_is_list($value) && $value !== []) {
            $fail('The :attribute must be an object, not an array.');

            return;
        }

        $encoded = json_encode($value);

        if ($encoded === false || mb_strlen($encoded, '8bit') > self::MAX_BYTES) {
            $fail('The :attribute must not exceed '.self::MAX_BYTES.' bytes of JSON.');

            return;
        }

        if (count($value) > self::MAX_KEYS) {
            $fail('The :attribute must not have more than '.self::MAX_KEYS.' top-level keys.');

            return;
        }

        if ($this->depth($value) > self::MAX_DEPTH) {
            $fail('The :attribute must not be nested more than '.self::MAX_DEPTH.' levels deep.');
        }
    }

    private function depth(array $value): int
    {
        $deepest = 1;

        foreach ($value as $item) {
            if (is_array($item)) {
                $deepest = max($deepest, 1 + $this->depth($item));
            }
        }

        return $deepest;
    }
}
