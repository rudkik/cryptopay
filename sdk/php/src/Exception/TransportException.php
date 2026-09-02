<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Exception;

/**
 * Raised when the underlying transport (cURL, or a custom TransportInterface)
 * fails to complete a request — DNS failures, connection refused, timeouts, etc.
 */
class TransportException extends CryptoPayException
{
}
