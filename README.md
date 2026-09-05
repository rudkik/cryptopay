# CryptoPay

Процессинг приёма платежей в **USDT / USDC** в сетях **Ethereum (ERC‑20)**, **BSC (BEP‑20)** и **Tron (TRC‑20)**:
зачисление средств мерчантам, hosted‑checkout, продажа токенов (token sale, опционально), вебхуки и merchant API
для подключения к любым вашим проектам.

| Сервис      | Стек                                   | Назначение                                                         |
|-------------|----------------------------------------|--------------------------------------------------------------------|
| `backend/`  | Laravel 13, PHP 8.4, PostgreSQL, Redis | REST API (merchant / admin / public / internal), очереди, вебхуки  |
| `frontend/` | Vue 3, Vite, TypeScript, Tailwind      | Админка (светлая тёплая тема) + публичная страница оплаты `/pay/:id` |
| `watcher/`  | Node 22, TypeScript, ethers, TronGrid  | Микросервис: деривация HD‑адресов, сканирование сетей, подтверждения |

Полный контракт между сервисами — в [SPEC.md](SPEC.md), продакшен-развёртывание — в [DEPLOY.md](DEPLOY.md), безопасность — в [SECURITY.md](SECURITY.md).

## Быстрый старт (Docker)

```bash
cp .env.example .env          # при необходимости отредактируйте
make secrets                  # заполнит APP_KEY, пароли БД/Redis, internal token (для прода: make secrets-prod)
make up                       # docker compose up -d --build
```

Через ~1 минуту:

- Админка: http://localhost:8095 — логин `admin@cryptopay.local` / `password` (меняется в `.env`)
- Merchant API: http://localhost:8095/api/v1 — демо‑ключ печатается в логах `app` при первом старте
  (`docker compose logs app | grep cp_live_`)
- Документация API с примерами: http://localhost:8095/docs, интерактивный Swagger UI: http://localhost:8095/swagger
  (обе страницы открываются **без входа** — ссылку можно давать интеграторам; старые адреса `/admin/docs`
  и `/admin/swagger` редиректят сюда же)

Разделы админки: **Dashboard**, **Invoices**, **Transactions**, **Webhooks**, **Services**, **Wallet**, **Networks**,
**Admin users**, **API docs**, **Swagger**. **Service** — это подключённый проект (в API он по-прежнему `merchant`):
свои API-ключи, webhook URL и балансы. Раздел **Tokens** (продажа токенов) появляется только при
`TOKEN_SALE_ENABLED=true`.

### Ключи кошелька (xpub)

Сервис хранит **только публичные ключи** (xpub): адреса выводятся из них, приватные ключи в системе отсутствуют.

```bash
make keys                     # печатает mnemonic, EVM_XPUB и TRON_XPUB
```

Сохраните мнемоник в надёжном месте — это единственный способ распоряжаться средствами.

**Основной способ задать xpub — админка: «Wallet» (`/admin/wallet`).** Там для каждой сети видно, откуда взят ключ
(`database` / `env` / не задан), маска ключа, путь деривации, сколько адресов выдано и сколько на них пришло. Перед
сохранением можно нажать «Preview addresses» и сверить первые 5 адресов со своим кошельком. Ключ, сохранённый в базе,
**перекрывает** env; для ethereum/bsc один EVM‑ключ сохраняется сразу в обе сети. Полный xpub наружу никогда не
возвращается — только маска.

Env‑переменные `EVM_XPUB` / `TRON_XPUB` в `.env` остаются **fallback'ом** (после правки: `docker compose up -d watcher`).
Если для сети нет ни ключа в базе, ни env‑ключа, сеть не предлагается плательщику и создание счёта на ней возвращает
`422` — настройте её на странице Wallet.

Смена xpub при уже выданных адресах разрешена: старые адреса остаются за старым кошельком и продолжают мониториться,
индекс деривации продолжается и не сбрасывается.

Публичные RPC (`ETH_RPC_URL`, `BSC_RPC_URL`) подходят для старта; для Tron настоятельно рекомендуется бесплатный
`TRON_API_KEY` с trongrid.io — без него TronGrid жёстко ограничивает частоту запросов, и watcher догоняет сеть медленнее.
Для продакшена используйте собственные/платные ноды.

Без xpub сервис запускается, но сеть считается ненастроенной: она скрыта из выбора, создание счёта на ней даёт `422`,
а прямая аллокация адреса — `503 wallet_not_configured`. Для демо/QA можно включать
`SIMULATION_ENABLED=true` и нажимать «Simulate payment» в карточке счёта в админке — платёж проходит через тот же
конвейер, что и реальный.

## Переменные окружения

Все сервисы читают один `.env` в корне (compose раздаёт его через `x-backend-env`); полный список
с комментариями — в [.env.example](.env.example), контракт — SPEC.md §8. Что важно знать сразу:

| Переменная | Дефолт в compose | Зачем |
|---|---|---|
| `APP_PORT` | `8095` | внешний порт nginx (единственный публикуемый) |
| `APP_ENV` / `APP_DEBUG` | `local` / `false` | вне `local` `make check-env` падает на `APP_DEBUG=true` |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | `admin@cryptopay.local` / `password` | первый админ, создаётся сидером |
| `DB_PASSWORD` | `secret` | плейсхолдер, заменить вне `local` |
| `REDIS_PASSWORD` | пусто | пусто = redis без пароля; вне `local` задайте (`--requirepass` включается только при непустом значении) |
| `INTERNAL_API_TOKEN` | `change-me-…` | секрет backend ↔ watcher; с плейсхолдером `/api/internal/*` отдаёт 503, а watcher не стартует |
| `SANCTUM_EXPIRATION` | `720` | время жизни токена админки в минутах (12 ч) |
| `WEBHOOK_TIMEOUT` | `10` | таймаут одной попытки доставки вебхука, секунды (значение зажимается в 1…10) |
| `WEBHOOK_ALLOW_PRIVATE` | `false` | защита от SSRF: `true` разрешает вебхуки на приватные адреса и compose-имена — только для локального демо |
| `SIMULATION_ENABLED` | `false` | кнопка «Simulate payment» в админке (демо/QA) |
| `TOKEN_SALE_ENABLED` | `false` | модуль продажи токенов; при `false` все его маршруты отдают `404` |
| `EVM_XPUB` / `TRON_XPUB` | пусто | fallback для xpub, если ключ не задан в админке (страница Wallet) |
| `ETH_RPC_URL` / `BSC_RPC_URL` / `TRON_API_URL` / `TRON_API_KEY` | публичные ноды | доступ к сетям; для Tron настоятельно нужен свой ключ |
| `WATCHER_ENABLED`, `EVM_BATCH_BLOCKS`, `POLL_INTERVAL_MS`, `LOG_LEVEL` | `true`, `20`, `5000`, `info` | поведение watcher'а (см. `watcher/README.md`) |

Обратите внимание: в `.env.example` (локальный стенд) `APP_DEBUG`, `SIMULATION_ENABLED` и
`WEBHOOK_ALLOW_PRIVATE` включены, а в `docker-compose.yml` дефолты обратные — для боя оставляйте
их выключенными и проверяйте `make check-env`.

## Безопасность

Модель угроз, архитектурные решения, результаты аудита и чеклист перед запуском — в [SECURITY.md](SECURITY.md).
Перед деплоем выполните `make check-env`: команда завершится ошибкой, если в `.env` остались placeholder-секреты.

## Как подключить оплату к своему проекту

1. В админке создайте сервис (**Services → New service**; в API это `merchant`) и API‑ключ (`cp_live_…`), укажите `webhook_url`.
2. Создайте счёт:

```bash
curl -X POST http://localhost:8095/api/v1/invoices \
  -H "Authorization: Bearer cp_live_..." -H "Content-Type: application/json" \
  -d '{"amount":"100","currency":"USDT","network":"tron","external_id":"order-42","success_url":"https://shop.example/thanks"}'
```

3. Перенаправьте покупателя на `payment_url` из ответа (hosted checkout с QR и таймером) или покажите `address` у себя.
4. Получите вебхук `invoice.paid` (подпись `X-CryptoPay-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body)`)
   или опросите `GET /api/v1/invoices/{id}`.
5. Обработайте `invoice.reversed`: реорг может забрать уже подтверждённый платёж, и тогда счёт перестаёт быть
   оплаченным — по этому событию нужно отозвать всё, что вы выдали по счёту (SPEC §6.2).

Покупка токенов (опциональный модуль, по умолчанию выключен — включается `TOKEN_SALE_ENABLED=true`):
`POST /api/v1/token-purchases` создаёт счёт, после оплаты токены зачисляются на `customer_id`
(`GET /api/v1/customers/{customer_id}/holdings`). При выключенном модуле все эндпоинты token sale отдают `404`,
а раздел «Tokens» в админке скрыт. Полный список эндпоинтов — SPEC.md §6 и страница `/docs`.

## SDK для подключения (готовые обёртки)

| Пакет | Путь | Что умеет |
|-------|------|-----------|
| `cryptopay/sdk` (Composer, PHP ≥ 8.1) | `sdk/php/` | клиент всех методов `/api/v1`, DTO, проверка подписи вебхука, Laravel service provider + middleware `cryptopay.webhook` + фасад |
| `@cryptopay/sdk` (npm, Node ≥ 18) | `sdk/js/` | то же на TypeScript (ESM + CJS), `verifyWebhook`, middleware `cryptoPayWebhook` |

```php
$cp = new \CryptoPay\Sdk\Client('cp_live_...', 'http://localhost:8095');
$invoice = $cp->createInvoice(['amount' => '50', 'currency' => 'USDT', 'network' => 'tron', 'customer_id' => 'user-123']);
return redirect($invoice->paymentUrl);

// приём вебхука
$event = \CryptoPay\Sdk\Webhook::verify($rawBody, $headers, $webhookSecret);
if ($event->isPaid()) { /* зачислить $event->invoice->amountConfirmed пользователю $event->invoice->customerId */ }
if ($event->isReversed()) { /* реорг забрал платёж — отозвать выданное по $event->invoice->id */ }
```

Подробные примеры и Laravel-интеграция: `sdk/php/README.md`, `sdk/js/README.md`, страница `/docs` → SDKs.

## Переезд с IP на домен и HTTPS

Сначала можно развернуть на голом IP (`APP_URL=http://1.2.3.4:8095`) и подключить свои сервисы: API-ключи,
подписи вебхуков и адреса кошельков от хоста не зависят, а `payment_url` в ответах строится из `APP_URL`
в момент запроса, поэтому после смены адреса даже старые счета получат новые ссылки. Учтите, что по HTTP
API-ключ идёт в открытом виде — используйте IP только внутри доверенной сети или для теста.

Переключение на домен с сертификатом Let's Encrypt делается без пересборки:

```bash
# .env
APP_URL=https://pay.example.com
DOMAIN=pay.example.com
ACME_EMAIL=admin@example.com
APP_BIND=127.0.0.1           # nginx больше не публикуется наружу, снаружи только caddy :80/:443

docker compose --profile tls up -d
```

Caddy получит сертификат сам (нужны открытые 80/443 и A-запись домена), включит HTTP→HTTPS и HSTS.
На стороне ваших сервисов меняется только base URL в настройках SDK; вебхуки продолжают работать, так как
`webhook_url` указывает на ваши сервисы, а не на процессинг. Если ваши сервисы живут на приватных адресах,
на время IP-этапа включите `WEBHOOK_ALLOW_PRIVATE=true`, а с переходом на публичные домены выключите.

## Полезные команды

```bash
make logs                     # логи всех контейнеров
make test                     # php artisan test в одноразовом контейнере (sqlite in-memory)
make test ARGS="--filter=Invoice"   # то же, с аргументами php artisan test
make check-env                # проверить .env на placeholder-секреты
make shell                    # sh в контейнере app
docker compose logs -f watcher
```

## Архитектура потока платежа

```
merchant ──POST /api/v1/invoices──▶ backend ──derive──▶ watcher (xpub → адрес)
buyer ──USDT/USDC──▶ адрес ──▶ blockchain ◀── watcher сканирует блоки
watcher ──POST /api/internal/transactions (detected → confirmed)──▶ backend
backend: invoice pending → confirming → paid, баланс + ledger, webhook → merchant
```
