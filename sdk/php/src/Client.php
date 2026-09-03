<?php

declare(strict_types=1);

namespace CryptoPay\Sdk;

use CryptoPay\Sdk\Dto\Balances;
use CryptoPay\Sdk\Dto\Holding;
use CryptoPay\Sdk\Dto\Invoice;
use CryptoPay\Sdk\Dto\Merchant;
use CryptoPay\Sdk\Dto\Network;
use CryptoPay\Sdk\Dto\Paginated;
use CryptoPay\Sdk\Dto\Token;
use CryptoPay\Sdk\Dto\TokenPurchase;
use CryptoPay\Sdk\Dto\TokenPurchaseResult;
use CryptoPay\Sdk\Dto\Transaction;
use CryptoPay\Sdk\Exception\ApiException;
use CryptoPay\Sdk\Exception\TransportException;
use CryptoPay\Sdk\Http\CurlTransport;
use CryptoPay\Sdk\Http\Response;
use CryptoPay\Sdk\Http\TransportInterface;
use InvalidArgumentException;
use Throwable;

/**
 * HTTP client for the CryptoPay merchant API (`/api/v1/*`).
 *
 * @see https://github.com/cryptopay (README.md in this package for usage)
 */
final class Client
{
    private const DEFAULT_USER_AGENT = 'cryptopay-php-sdk/1.0';

    private readonly string $baseUrl;

    private readonly TransportInterface $transport;

    /** @var array<string, string> */
    private readonly array $defaultHeaders;

    /**
     * @param  array{
     *     timeout?: int,
     *     connect_timeout?: int,
     *     transport?: TransportInterface,
     *     user_agent?: string,
     *     headers?: array<string, string>,
     * }  $options
     */
    public function __construct(string $apiKey, string $baseUrl, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('CryptoPay API key must not be empty.');
        }

        if (trim($baseUrl) === '') {
            throw new InvalidArgumentException('CryptoPay base URL must not be empty.');
        }

        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);

        $timeout = (int) ($options['timeout'] ?? 30);
        $connectTimeout = (int) ($options['connect_timeout'] ?? 10);
        $userAgent = (string) ($options['user_agent'] ?? self::DEFAULT_USER_AGENT);

        $this->transport = $options['transport'] ?? new CurlTransport($timeout, $connectTimeout);

        $this->defaultHeaders = array_merge(
            [
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
                'User-Agent' => $userAgent,
            ],
            (array) ($options['headers'] ?? [])
        );
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if (str_ends_with($baseUrl, '/api/v1')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/api/v1'));
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException(
                'CryptoPay base URL must be an http:// or https:// URL, got: '.$baseUrl
            );
        }

        // Plain http is legitimate against a local stack (docker-compose exposes
        // the API on http://localhost:8095), but anywhere else it would put the
        // API key on the wire in clear text. Warn instead of hard-failing so a
        // local install keeps working.
        if ($scheme === 'http' && ! self::isLocalHost((string) parse_url($baseUrl, PHP_URL_HOST))) {
            trigger_error(
                'CryptoPay: base URL uses plain http against a non-local host — the API key is sent unencrypted. Use https://.',
                E_USER_WARNING
            );
        }

        return $baseUrl;
    }

    private static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], true)
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    /**
     * Keep the API key out of var_dump()/dd() output — a debug statement left in
     * a controller should not print a live merchant key into a log.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'transport' => $this->transport::class,
            'defaultHeaders' => array_merge($this->defaultHeaders, ['Authorization' => 'Bearer [redacted]']),
        ];
    }

    // ------------------------------------------------------------------
    // Invoices
    // ------------------------------------------------------------------

    /**
     * @param  array<mixed>  $params
     */
    public function createInvoice(array $params, ?string $idempotencyKey = null): Invoice
    {
        $data = $this->request('POST', 'invoices', body: $params, idempotencyKey: $idempotencyKey);

        return Invoice::fromArray((array) ($data['data'] ?? []));
    }

    public function getInvoice(string $id): Invoice
    {
        $data = $this->request('GET', 'invoices/'.$this->encodeSegment($id));

        return Invoice::fromArray((array) ($data['data'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $filters
     *
     * @return Paginated<Invoice>
     */
    public function listInvoices(array $filters = []): Paginated
    {
        $data = $this->request('GET', 'invoices', query: $filters);

        return $this->hydratePaginated($data, [Invoice::class, 'fromArray']);
    }

    public function cancelInvoice(string $id): Invoice
    {
        $data = $this->request('POST', 'invoices/'.$this->encodeSegment($id).'/cancel');

        return Invoice::fromArray((array) ($data['data'] ?? []));
    }

    // ------------------------------------------------------------------
    // Networks / balances / transactions
    // ------------------------------------------------------------------

    /**
     * @return Network[]
     */
    public function networks(): array
    {
        $data = $this->request('GET', 'networks');

        return array_map(
            static fn (array $item): Network => Network::fromArray($item),
            (array) ($data['data'] ?? [])
        );
    }

    public function balances(): Balances
    {
        $data = $this->request('GET', 'balances');

        return Balances::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $filters
     *
     * @return Paginated<Transaction>
     */
    public function transactions(array $filters = []): Paginated
    {
        $data = $this->request('GET', 'transactions', query: $filters);

        return $this->hydratePaginated($data, [Transaction::class, 'fromArray']);
    }

    // ------------------------------------------------------------------
    // Tokens / token purchases / holdings
    // ------------------------------------------------------------------

    /**
     * @return Token[]
     */
    public function tokens(): array
    {
        $data = $this->request('GET', 'tokens');

        return array_map(
            static fn (array $item): Token => Token::fromArray($item),
            (array) ($data['data'] ?? [])
        );
    }

    public function getToken(string $id): Token
    {
        $data = $this->request('GET', 'tokens/'.$this->encodeSegment($id));

        return Token::fromArray((array) ($data['data'] ?? []));
    }

    /**
     * @param  array<mixed>  $params
     */
    public function createTokenPurchase(array $params, ?string $idempotencyKey = null): TokenPurchaseResult
    {
        $data = $this->request('POST', 'token-purchases', body: $params, idempotencyKey: $idempotencyKey);

        return TokenPurchaseResult::fromArray($data);
    }

    public function getTokenPurchase(string $id): TokenPurchase
    {
        $data = $this->request('GET', 'token-purchases/'.$this->encodeSegment($id));

        return TokenPurchase::fromArray((array) ($data['data'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $filters
     *
     * @return Paginated<TokenPurchase>
     */
    public function listTokenPurchases(array $filters = []): Paginated
    {
        $data = $this->request('GET', 'token-purchases', query: $filters);

        return $this->hydratePaginated($data, [TokenPurchase::class, 'fromArray']);
    }

    /**
     * @return Holding[]
     */
    public function customerHoldings(string $customerId): array
    {
        $data = $this->request('GET', 'customers/'.$this->encodeSegment($customerId).'/holdings');

        return array_map(
            static fn (array $item): Holding => Holding::fromArray($item),
            (array) ($data['data'] ?? [])
        );
    }

    // ------------------------------------------------------------------
    // Merchant
    // ------------------------------------------------------------------

    public function me(): Merchant
    {
        $data = $this->request('GET', 'me');

        return Merchant::fromArray((array) ($data['data'] ?? []));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function encodeSegment(string $segment): string
    {
        return rawurlencode($segment);
    }

    /**
     * @template T
     *
     * @param  array<mixed>  $data
     * @param  callable(array<mixed>): T  $hydrate
     * @return Paginated<T>
     */
    private function hydratePaginated(array $data, callable $hydrate): Paginated
    {
        $items = [];
        foreach ((array) ($data['data'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = $hydrate($item);
            }
        }

        return new Paginated(
            data: $items,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
            links: is_array($data['links'] ?? null) ? $data['links'] : [],
            raw: $data,
        );
    }

    /**
     * @param  array<mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $idempotencyKey = null,
        array $query = [],
    ): array {
        $url = $this->baseUrl.'/api/v1/'.ltrim($path, '/');

        $queryString = $this->buildQueryString($query);
        if ($queryString !== '') {
            $url .= '?'.$queryString;
        }

        $headers = $this->defaultHeaders;

        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = $this->transport->send($method, $url, $headers, $encodedBody);
        } catch (TransportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransportException('CryptoPay request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->status >= 400) {
            throw $this->toApiException($response);
        }

        if (trim($response->body) === '') {
            return [];
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildQueryString(array $query): string
    {
        $filtered = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $filtered[$key] = $value;
        }

        if ($filtered === []) {
            return '';
        }

        return http_build_query($filtered);
    }

    /** `Retry-After` in seconds, when the server sent a plain numeric value. */
    private static function retryAfter(Response $response): ?int
    {
        $value = $response->headers['retry-after'] ?? null;

        return is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1
            ? (int) trim($value)
            : null;
    }

    private function toApiException(Response $response): ApiException
    {
        $status = $response->status;
        $raw = $response->body;

        $decoded = null;
        if (trim($raw) !== '') {
            $tmp = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $decoded = $tmp;
            }
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;

        if ($error !== null) {
            $code = (string) ($error['code'] ?? ($status >= 500 ? 'server_error' : 'http_error'));
            $message = (string) ($error['message'] ?? ('HTTP '.$status));
            $details = is_array($error['details'] ?? null) ? $error['details'] : [];

            return new ApiException($message, $code, $status, $details, self::retryAfter($response));
        }

        $code = $status >= 500 ? 'server_error' : 'http_error';

        return new ApiException(
            'HTTP '.$status,
            $code,
            $status,
            ['body' => substr($raw, 0, 2048)],
            self::retryAfter($response)
        );
    }
}
