<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Laravel;

use CryptoPay\Sdk\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \CryptoPay\Sdk\Dto\Invoice createInvoice(array $params, ?string $idempotencyKey = null)
 * @method static \CryptoPay\Sdk\Dto\Invoice getInvoice(string $id)
 * @method static \CryptoPay\Sdk\Dto\Paginated listInvoices(array $filters = [])
 * @method static \CryptoPay\Sdk\Dto\Invoice cancelInvoice(string $id)
 * @method static \CryptoPay\Sdk\Dto\Network[] networks()
 * @method static \CryptoPay\Sdk\Dto\Balances balances()
 * @method static \CryptoPay\Sdk\Dto\Paginated transactions(array $filters = [])
 * @method static \CryptoPay\Sdk\Dto\Token[] tokens()
 * @method static \CryptoPay\Sdk\Dto\Token getToken(string $id)
 * @method static \CryptoPay\Sdk\Dto\TokenPurchaseResult createTokenPurchase(array $params, ?string $idempotencyKey = null)
 * @method static \CryptoPay\Sdk\Dto\TokenPurchase getTokenPurchase(string $id)
 * @method static \CryptoPay\Sdk\Dto\Paginated listTokenPurchases(array $filters = [])
 * @method static \CryptoPay\Sdk\Dto\Holding[] customerHoldings(string $customerId)
 * @method static \CryptoPay\Sdk\Dto\Merchant me()
 *
 * @see Client
 */
final class CryptoPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cryptopay';
    }
}
