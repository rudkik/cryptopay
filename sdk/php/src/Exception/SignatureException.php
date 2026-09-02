<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Exception;

/**
 * Raised when a webhook payload fails signature verification: missing
 * headers, a stale timestamp, or a signature mismatch.
 */
class SignatureException extends CryptoPayException
{
}
