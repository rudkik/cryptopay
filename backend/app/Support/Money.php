<?php

namespace App\Support;

/**
 * All monetary math is performed with bcmath at scale 18. Values are always
 * carried as strings; floats are never used for arithmetic.
 */
final class Money
{
    public const SCALE = 18;

    /**
     * Normalise any scalar (string, int, float, null) into a plain decimal
     * string at scale 18. Handles scientific notation that PDO/SQLite may
     * hand back for float-typed columns.
     */
    public static function normalize(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_float($value) || is_int($value)) {
            $value = self::fromScientific(sprintf('%.18F', $value));
        }

        $value = trim((string) $value);

        if ($value === '' || ! preg_match('/^-?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $value)) {
            return self::zero();
        }

        if (stripos($value, 'e') !== false) {
            $value = self::fromScientific($value);
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    public static function add(mixed $a, mixed $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function mul(mixed $a, mixed $b): string
    {
        return bcmul(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function div(mixed $a, mixed $b): string
    {
        $divisor = self::normalize($b);

        if (bccomp($divisor, self::zero(), self::SCALE) === 0) {
            return self::zero();
        }

        return bcdiv(self::normalize($a), $divisor, self::SCALE);
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function isPositive(mixed $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function isZero(mixed $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /**
     * Render a value with exactly $decimals fraction digits, rounded half-up.
     *
     * Rounding rather than truncating matters because SQLite (used by the test
     * suite) stores decimal columns with float affinity, so an exact 99.6 comes
     * back as 99.599999999999994. Postgres returns exact strings and is
     * unaffected either way. Comparisons always use the full scale-18 value;
     * only presentation is rounded.
     */
    public static function format(mixed $value, int $decimals): string
    {
        $decimals = max(0, min(self::SCALE, $decimals));

        $value = self::normalize($value);
        $negative = str_starts_with($value, '-');

        if ($negative) {
            $value = substr($value, 1);
        }

        // bcadd truncates to the given scale, so adding half a unit of the last
        // kept digit first turns truncation into round-half-up.
        $half = '0.'.str_repeat('0', $decimals).'5';
        $rounded = bcadd($value, $half, $decimals);

        return $negative && bccomp($rounded, '0', $decimals) !== 0 ? '-'.$rounded : $rounded;
    }

    /**
     * Render for display, trimming trailing zeros but keeping at least
     * $minDecimals digits (used for prices, which have no fixed precision).
     */
    public static function trim(mixed $value, int $minDecimals = 2): string
    {
        $value = bcadd(self::normalize($value), '0', self::SCALE);

        if (! str_contains($value, '.')) {
            return $value;
        }

        [$int, $frac] = explode('.', $value, 2);
        $frac = rtrim($frac, '0');
        $frac = str_pad($frac, $minDecimals, '0');

        return $frac === '' ? $int : $int.'.'.$frac;
    }

    /** Convert a human amount to base units ("100" @ 6 decimals => "100000000"). */
    public static function toBaseUnits(mixed $value, int $decimals): string
    {
        $scaled = bcmul(self::normalize($value), bcpow('10', (string) $decimals, 0), 0);

        return $scaled === '' ? '0' : $scaled;
    }

    /** Convert base units back to a human amount. */
    public static function fromBaseUnits(mixed $raw, int $decimals): string
    {
        $raw = preg_replace('/[^0-9-]/', '', (string) $raw) ?: '0';

        return bcdiv($raw, bcpow('10', (string) $decimals, 0), self::SCALE);
    }

    private static function fromScientific(string $value): string
    {
        if (stripos($value, 'e') === false) {
            return $value;
        }

        [$mantissa, $exponent] = preg_split('/[eE]/', $value, 2);
        $exponent = (int) $exponent;

        $sign = '';
        if (str_starts_with($mantissa, '-')) {
            $sign = '-';
            $mantissa = substr($mantissa, 1);
        } elseif (str_starts_with($mantissa, '+')) {
            $mantissa = substr($mantissa, 1);
        }

        [$int, $frac] = str_contains($mantissa, '.')
            ? explode('.', $mantissa, 2)
            : [$mantissa, ''];

        if ($exponent >= 0) {
            $frac = str_pad($frac, $exponent, '0');
            $int .= substr($frac, 0, $exponent);
            $frac = substr($frac, $exponent);
        } else {
            $shift = -$exponent;
            $int = str_pad($int, $shift + 1, '0', STR_PAD_LEFT);
            $frac = substr($int, -$shift).$frac;
            $int = substr($int, 0, -$shift);
        }

        $result = $sign.($int === '' ? '0' : $int).($frac === '' ? '' : '.'.$frac);

        return $result;
    }
}
