# CryptoPay

Процессинг приёма платежей в **USDT / USDC** в сетях **Ethereum (ERC‑20)**, **BSC (BEP‑20)** и **Tron (TRC‑20)**:
зачисление средств мерчантам, hosted‑checkout, продажа токенов (token sale), вебхуки и merchant API для подключения
к любым вашим проектам.

| Сервис      | Стек                                   | Назначение                                                         |
|-------------|----------------------------------------|--------------------------------------------------------------------|
| `backend/`  | Laravel 13, PHP 8.4, PostgreSQL, Redis | REST API (merchant / admin / public / internal), очереди, вебхуки  |
| `frontend/` | Vue 3, Vite, TypeScript, Tailwind      | Админка (тёмно‑фиолетовая тема) + публичная страница оплаты `/pay/:id` |
| `watcher/`  | Node 22, TypeScript, ethers, TronGrid  | Микросервис: деривация HD‑адресов, сканирование сетей, подтверждения |

Полный контракт между сервисами — в [SPEC.md](SPEC.md).

## Быстрый старт (Docker)

```bash
cp .env.example .env          # при необходимости отредактируйте
make up                       # docker compose up -d --build
```

Через ~1 минуту:

- Админка: http://localhost:8095 — логин `admin@cryptopay.local` / `password` (меняется в `.env`)
- Merchant API: http://localhost:8095/api/v1 — демо‑ключ печатается в логах `app` при первом старте
  (`docker compose logs app | grep cp_live_`)
- Документация API с примерами: http://localhost:8095/admin/docs

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

## Безопасность

Модель угроз, архитектурные решения, результаты аудита и чеклист перед запуском — в [SECURITY.md](SECURITY.md).
Перед деплоем выполните `make check-env`: команда завершится ошибкой, если в `.env` остались placeholder-секреты.

## Как подключить оплату к своему проекту

1. В админке создайте мерчанта и API‑ключ (`cp_live_…`), укажите `webhook_url`.
2. Создайте счёт:

```bash
curl -X POST http://localhost:8095/api/v1/invoices \
  -H "Authorization: Bearer cp_live_..." -H "Content-Type: application/json" \
  -d '{"amount":"100","currency":"USDT","network":"tron","external_id":"order-42","success_url":"https://shop.example/thanks"}'
```

3. Перенаправьте покупателя на `payment_url` из ответа (hosted checkout с QR и таймером) или покажите `address` у себя.
4. Получите вебхук `invoice.paid` (подпись `X-CryptoPay-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body)`)
   или опросите `GET /api/v1/invoices/{id}`.

Покупка токенов: `POST /api/v1/token-purchases` создаёт счёт, после оплаты токены зачисляются на `customer_id`
(`GET /api/v1/customers/{customer_id}/holdings`). Полный список эндпоинтов — SPEC.md §6 и страница `/admin/docs`.

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
```

Подробные примеры и Laravel-интеграция: `sdk/php/README.md`, `sdk/js/README.md`, страница `/admin/docs` → SDKs.

## Полезные команды

```bash
make logs                     # логи всех контейнеров
make test                     # php artisan test внутри контейнера
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
