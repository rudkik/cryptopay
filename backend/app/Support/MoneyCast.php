<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts decimal(36,18) columns to normalised scale-18 strings on the way out
 * and on the way in, so no float ever touches a monetary value.
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Money::normalize($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Money::normalize($value);
    }
}
