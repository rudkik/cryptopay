<?php

namespace App\Rules;

use App\Support\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `webhook_url` is the one merchant-controlled value the backend itself calls,
 * so it is checked against the SSRF guard at write time as well as at delivery
 * time (a DNS record can change in between).
 */
class WebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be an absolute http(s) URL.');

            return;
        }

        if ($reason = OutboundUrlGuard::reject($value)) {
            $fail('The :attribute is not an acceptable webhook target. '.$reason);
        }
    }
}
