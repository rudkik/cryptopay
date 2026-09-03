# CryptoPay PHP SDK

Официальный PHP SDK для приёма платежей через CryptoPay (USDT/USDC в сетях
Ethereum, BSC, Tron). Без зависимостей от фреймворков: только `ext-json` и
`ext-curl`. Есть готовая интеграция с Laravel (service provider, фасад,
middleware для проверки вебхуков) — она активируется автоматически, если в
проекте установлен `illuminate/support`.

## Установка

```bash
composer require cryptopay/sdk
```

Требования: PHP `^8.1`, расширения `ext-json` и `ext-curl`.

## Быстрый старт: создание счёта

```php
use CryptoPay\Sdk\Client;
use CryptoPay\Sdk\Exception\ApiException;

$client = new Client(
    apiKey: 'cp_live_...',
    baseUrl: 'https://pay.example.com', // или http://localhost:8095 для локального стенда
);

try {
    $invoice = $client->createInvoice([
        'amount' => '12.50',        // decimal string, не float
        'currency' => 'USDT',       // USDT | USDC
        'network' => 'tron',        // ethereum | bsc | tron
        'external_id' => 'order-1042',
        'description' => 'Заказ #1042',
        'customer_email' => 'buyer@example.com',
        'success_url' => 'https://shop.example.com/thanks',
        'cancel_url' => 'https://shop.example.com/cart',
        'metadata' => ['order_id' => 1042],
        'expires_in' => 3600, // сек, максимум 86400
    ], idempotencyKey: bin2hex(random_bytes(16)) /* любой свой уникальный ключ на операцию */);

    echo $invoice->id, "\n";
    echo $invoice->address, "\n";
    echo $invoice->paymentUrl, "\n";
} catch (ApiException $e) {
    // см. раздел "Обработка ошибок" ниже
}
```

Все денежные суммы (`amount`, `amountReceived`, `amountConfirmed` и т.д.)
приходят и хранятся как **строки**, а не `float` — используйте `bcmath`
(`bcadd`, `bccomp`, ...) или `GMP` для арифметики, чтобы не терять точность.

## Редирект на страницу оплаты

После создания счёта отправьте покупателя на `payment_url` — это готовая
hosted-страница оплаты со встроенным QR-кодом и опросом статуса:

```php
header('Location: '.$invoice->paymentUrl);
exit;
```

## Проверка вебхука на чистом PHP

CryptoPay отправляет вебхуки на `webhook_url` мерчанта и подписывает тело
запроса HMAC-SHA256. Пример приёмника (см. также
`examples/webhook-server.php`, запускается через
`php -S 127.0.0.1:8099 examples/webhook-server.php`):

```php
use CryptoPay\Sdk\Webhook;
use CryptoPay\Sdk\Exception\SignatureException;

$rawBody = file_get_contents('php://input');
$headers = getallheaders(); // или $_SERVER, ключи HTTP_X_CRYPTOPAY_* тоже поддерживаются

try {
    $event = Webhook::verify($rawBody, $headers, secret: 'whsec_...', tolerance: 300);
} catch (SignatureException $e) {
    http_response_code(400);
    exit;
}

if ($event->isPaid()) {
    // $event->invoice->id, $event->invoice->amount, ...
}
```

Важно: `Webhook::verify()` должен получать **сырое, неизменённое** тело
запроса — если что-то выше по стеку уже распарсило и заново сериализовало
JSON, подпись не совпадёт.

Проверить подпись вручную (например, в тестах) можно так:

```php
$signature = Webhook::sign($secret, $timestamp, $rawBody); // 'sha256=' . hex hmac_sha256(...)
```

## Проверка вебхука в Laravel

1. Опубликуйте конфиг пакета:

   ```bash
   php artisan vendor:publish --tag=cryptopay-config
   ```

2. Заполните `.env`:

   ```env
   CRYPTOPAY_API_KEY=cp_live_...
   CRYPTOPAY_BASE_URL=http://localhost:8095
   CRYPTOPAY_WEBHOOK_SECRET=whsec_...
   CRYPTOPAY_WEBHOOK_TOLERANCE=300
   CRYPTOPAY_TIMEOUT=30
   ```

3. Подключите роут с middleware `cryptopay.webhook` (алиас регистрируется
   сервис-провайдером пакета автоматически):

   ```php
   use Illuminate\Support\Facades\Route;

   Route::post('/webhooks/cryptopay', function (\Illuminate\Http\Request $request) {
       $event = $request->attributes->get('cryptopay_event'); // CryptoPay\Sdk\WebhookEvent

       if ($event->isPaid()) {
           // обработать оплату, например по $event->invoice->externalId
       }

       return response()->json(['ok' => true]);
   })->middleware('cryptopay.webhook');
   ```

   Middleware сам проверяет подпись (`config('cryptopay.webhook_secret')`),
   при ошибке возвращает `400 {"error":{"code":"invalid_signature","message":"Invalid webhook signature."}}`
   (конкретная причина уходит в лог, а не в ответ — чтобы не подсказывать
   тому, кто перебирает подписи), а при успехе кладёт разобранное событие в
   `$request->attributes->get('cryptopay_event')`.

   **Важно (CSRF):** CryptoPay — server-to-server клиент, у него нет ни сессии,
   ни CSRF-токена. Роут в группе `web` отклонит доставку с 419 ещё до того, как
   middleware успеет что-то проверить. Объявляйте роут в `routes/api.php` (там
   CSRF-middleware нет) либо исключайте его явно:

   ```php
   // Laravel 11+ — bootstrap/app.php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->validateCsrfTokens(except: ['webhooks/cryptopay']);
   })

   // Laravel <= 10 — app/Http/Middleware/VerifyCsrfToken.php
   protected $except = ['webhooks/cryptopay'];

   // либо точечно на самом роуте
   Route::post('/webhooks/cryptopay', WebhookController::class)
       ->middleware('cryptopay.webhook')
       ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
   ```

   Запрос аутентифицирует подпись `X-CryptoPay-Signature`; CSRF-защита к этому
   ничего не добавляет.

   **Важно (сырое тело):** ничего до этого middleware не должно читать/изменять
   тело запроса (например, кастомный body-parsing middleware) — подпись
   считается по исходным байтам.

   **Важно (секрет):** пустой `CRYPTOPAY_WEBHOOK_SECRET` — это не «проверка
   выключена», а «проверку пройдёт кто угодно», поэтому `Webhook::verify()`
   отклоняет такую конфигурацию. Проверяйте, что переменная реально
   проброшена в окружение.

## Фасад `CryptoPay`

Если используете Laravel, доступен фасад (алиас `CryptoPay` регистрируется
автоматически через package discovery):

```php
use CryptoPay\Sdk\Laravel\CryptoPay;

$invoice = CryptoPay::createInvoice([
    'amount' => '10',
    'currency' => 'USDC',
    'network' => 'bsc',
]);

$balances = CryptoPay::balances();
```

Клиент собирается сервис-провайдером как singleton `cryptopay` из значений
`config('cryptopay.*')`.

## Пополнение баланса / продажа токенов через `customer_id`

Для сценария "покупатель пополняет баланс/покупает внутренний токен
мерчанта" используется `createTokenPurchase` — ответ **не обёрнут** в
`data`, а возвращает `{purchase, invoice}` напрямую:

```php
$result = $client->createTokenPurchase([
    'token_id' => 'tok_123',
    'token_amount' => '100',       // либо pay_amount
    'currency' => 'USDT',
    'network' => 'tron',
    'customer_id' => 'user-42',    // обязателен — по нему учитываются holdings
    'customer_email' => 'buyer@example.com',
], idempotencyKey: 'unique-key-per-purchase');

$result->purchase;  // TokenPurchase
$result->invoice;   // Invoice — на него так же можно отправить покупателя (paymentUrl)
```

Проверить статус покупки и остаток токенов у клиента:

```php
$purchase = $client->getTokenPurchase($result->purchase->id);
$purchase->isCompleted(); // bool

$holdings = $client->customerHoldings('user-42'); // Holding[]
foreach ($holdings as $holding) {
    echo $holding->token?->symbol, ': ', $holding->amount, "\n";
}
```

## Обработка ошибок

Любой ответ API со статусом `>= 400` выбрасывает `CryptoPay\Sdk\Exception\ApiException`:

```php
use CryptoPay\Sdk\Exception\ApiException;

try {
    $client->cancelInvoice($id);
} catch (ApiException $e) {
    $e->getMessage();          // сообщение от API
    $e->getErrorCode();        // 'validation_error' | 'not_found' | 'unauthenticated' |
                                // 'invalid_state' | 'rate_limited' | 'server_error' | 'http_error'
    $e->getHttpStatus();       // int, например 409
    $e->getDetails();          // array, например ['amount' => ['...']] для validation_error

    $e->isValidationError();   // code === 'validation_error'
    $e->isNotFound();          // code === 'not_found'
    $e->isRateLimited();       // code === 'rate_limited'
}
```

Сетевые сбои (обрыв соединения, DNS, таймаут) выбрасывают
`CryptoPay\Sdk\Exception\TransportException`. Обе ошибки наследуются от
`CryptoPay\Sdk\Exception\CryptoPayException`, так что можно ловить общий
случай одним `catch`.

## Идемпотентность

Методы `createInvoice()` и `createTokenPurchase()` принимают второй
аргумент `$idempotencyKey` — при повторной отправке того же запроса с тем
же ключом (в течение 24 часов) API вернёт тот же результат, а не создаст
дубликат. Генерируйте ключ один раз на бизнес-операцию (например, на клик
"Оплатить") и переиспользуйте его при ретраях:

```php
$idempotencyKey = bin2hex(random_bytes(16));

$invoice = $client->createInvoice($params, $idempotencyKey);
// при сетевом сбое и повторной попытке — тот же $idempotencyKey
```

## Справочник методов

| Метод | Возвращает | Описание |
|---|---|---|
| `createInvoice(array $params, ?string $idempotencyKey = null)` | `Invoice` | Создать счёт |
| `getInvoice(string $id)` | `Invoice` | Получить счёт по id |
| `listInvoices(array $filters = [])` | `Paginated<Invoice>` | Список счетов (`status`, `external_id`, `network`, `currency`, `from`, `to`, `per_page`, `page`) |
| `cancelInvoice(string $id)` | `Invoice` | Отменить счёт (только из `pending`) |
| `networks()` | `Network[]` | Список включённых сетей и их токенов |
| `balances()` | `Balances` | Балансы по валюте/сети + итоги по валюте |
| `transactions(array $filters = [])` | `Paginated<Transaction>` | Список транзакций (`invoice_id`, `network`, `status`, `per_page`, `page`) |
| `tokens()` | `Token[]` | Активные токены мерчанта |
| `getToken(string $id)` | `Token` | Токен по id |
| `createTokenPurchase(array $params, ?string $idempotencyKey = null)` | `TokenPurchaseResult` | Создать покупку токена (`{purchase, invoice}`) |
| `getTokenPurchase(string $id)` | `TokenPurchase` | Покупка токена по id |
| `listTokenPurchases(array $filters = [])` | `Paginated<TokenPurchase>` | Список покупок токенов (`customer_id`, `status`, `token_id`, `per_page`, `page`) |
| `customerHoldings(string $customerId)` | `Holding[]` | Остатки токенов у клиента |
| `me()` | `Merchant` | Профиль мерчанта, балансы, настройки вебхука |

Вспомогательные классы:

| Класс | Назначение |
|---|---|
| `CryptoPay\Sdk\Webhook::verify()/::sign()` | Проверка и подпись вебхуков |
| `CryptoPay\Sdk\WebhookEvent` | Разобранное событие вебхука (`->isPaid()`, `->invoice`, `->tokenPurchase`) |
| `CryptoPay\Sdk\Dto\Paginated` | Страница результатов (`IteratorAggregate`, `Countable`, `->total()`, `->hasMorePages()`) |
| `CryptoPay\Sdk\Http\TransportInterface` | Свой HTTP-транспорт вместо `CurlTransport` (например, для тестов) |

Каждый DTO — `final readonly class` с `::fromArray()` и `->toArray()`
(возвращает исходный «сырой» ответ API один в один).

## Примеры

- `examples/create-invoice.php` — создание, получение, список, балансы, отмена счёта.
- `examples/webhook-server.php` — приём и проверка вебхуков без единой зависимости (`php -S 127.0.0.1:8099 examples/webhook-server.php`).

## Опции клиента

```php
new Client($apiKey, $baseUrl, [
    'timeout' => 30,            // сек
    'connect_timeout' => 10,    // сек
    'user_agent' => 'my-app/1.0',
    'headers' => ['X-My-Header' => 'value'],
    'transport' => new MyTransportImplementation(), // для тестов/кастомного HTTP-стека
]);
```
