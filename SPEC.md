# CryptoPay — спецификация (единый контракт для всех сервисов)

Процессинг приёма платежей в **USDT / USDC** в сетях **Ethereum (ERC‑20)**, **BSC (BEP‑20)**, **Tron (TRC‑20)**.
Назначение: зачисление средств мерчанту (депозиты / оплата счетов) и **покупка токенов** (token sale) за USDT/USDC.
Вывод средств (withdrawals) в первой версии **не реализуется** — сервис хранит только xpub, приватных ключей нет.

## 1. Состав репозитория

```
cryptopay/
  docker-compose.yml          # postgres, redis, app (php-fpm), nginx, queue, scheduler, watcher
  .env.example                # единый env для compose
  docker/nginx/default.conf   # nginx: SPA + /api -> php-fpm
  backend/                    # Laravel 13 (PHP 8.4) — REST API (merchant / admin / public / internal), очереди, вебхуки
  frontend/                   # Vue 3 + Vite + TS + Pinia + Vue Router + Tailwind — админка + hosted checkout
  watcher/                    # Node 22 + TS — микросервис: деривация адресов, сканирование сетей, подтверждения
  SPEC.md, README.md
```

Внутренняя сеть compose: сервисы обращаются друг к другу по именам `app`, `watcher`, `postgres`, `redis`, `nginx`.

## 2. Сети и токены

| code       | Название     | chain_id | стандарт | confirmations (default) | explorer tx                                   |
|------------|--------------|----------|----------|-------------------------|-----------------------------------------------|
| `ethereum` | Ethereum     | 1        | ERC‑20   | 12                      | https://etherscan.io/tx/{hash}                |
| `bsc`      | BNB Smart Chain | 56    | BEP‑20   | 15                      | https://bscscan.com/tx/{hash}                 |
| `tron`     | Tron         | —        | TRC‑20   | 19                      | https://tronscan.org/#/transaction/{hash}     |

Контракты mainnet (сидируются в БД, редактируются в админке):

| network  | symbol | contract                                     | decimals |
|----------|--------|----------------------------------------------|----------|
| ethereum | USDT   | 0xdAC17F958D2ee523a2206206994597C13D831ec7   | 6        |
| ethereum | USDC   | 0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48   | 6        |
| bsc      | USDT   | 0x55d398326f99059fF775485246999027B3197955   | 18       |
| bsc      | USDC   | 0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d   | 18       |
| tron     | USDT   | TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t           | 6        |
| tron     | USDC   | TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8           | 6        |

Суммы везде передаются **строкой в человеко‑читаемых единицах** (`"100.5"`), в БД — `DECIMAL(36,18)`. Точность считать через bcmath (PHP) / BigInt (Node). В JSON суммы — строки.

## 3. Деривация адресов (HD‑кошелёк)

- Один мнемоник → xpub на каждую сеть. Runtime‑сервисам нужны **только xpub**.
- Пути: EVM `m/44'/60'/0'/0/{index}` (общий xpub для ethereum и bsc, но индексы ведутся раздельно, адреса могут совпадать — это нормально, `deposit_addresses` уникален по `(network, address)`), Tron `m/44'/195'/0'/0/{index}`.
- Tron‑адрес = base58check(0x41 ‖ last20(keccak256(pubkey_uncompressed[1:]))).
- Деривацию выполняет **watcher** (`POST /addresses/derive`), Laravel хранит `next_index` в таблице `wallets`.
- `watcher` имеет CLI `npm run keygen` → печатает mnemonic + `EVM_XPUB` + `TRON_XPUB`.

**Где хранится xpub (приоритет).** Основной способ — админка, страница **Wallet** (`/admin/wallet`, API `/api/admin/wallets`, SPEC §6.4): xpub лежит в `wallets.xpub` и **перекрывает** env. Env‑переменные watcher'а `EVM_XPUB` / `TRON_XPUB` остаются fallback'ом. Отсюда три значения вычисляемого поля `source`:

| `source` | когда | что происходит при выдаче адреса |
|---|---|---|
| `database` | `wallets.xpub` заполнен | Laravel шлёт `xpub` в теле `POST /addresses/derive` |
| `env` | `wallets.xpub` пуст, watcher в `/health` отдаёт `derivation.evm` / `derivation.tron` = true для семьи сети | `xpub` в теле не шлётся, watcher берёт свой env‑ключ |
| `none` | ни того ни другого | сеть **скрыта** из `options` публичного счёта и из `GET /api/v1/networks`; явный `network` при создании счёта → `422 validation_error` на поле `network`; прямой вызов аллокации → `503 wallet_not_configured` |

Проверка «сеть настроена» кешируется на 30 с (probe `/health` — тоже). Смена xpub при уже выданных адресах разрешена: старые адреса остаются за старым ключом и продолжают мониториться, `next_index` **не сбрасывается** (иначе один и тот же индекс был бы выдан дважды). Полный xpub не возвращается ни одному клиенту (только маска `первые 10 + '…' + последние 6`) и не пишется ни в логи, ни в `audit_logs`.

## 4. Схема БД (PostgreSQL 16)

Все id — `uuid` (кроме `users`). Timestamps везде. Денежные поля `decimal(36,18)`.

- `users` — админы: `name, email, password, role ('admin'|'viewer'), is_active`.
- `merchants` — `name, email, webhook_url (nullable), webhook_secret (string, генерируется), is_active, settings json`.
- `api_keys` — `merchant_id, name, key_prefix (первые 12 символов), key_hash (sha256), last_used_at, revoked_at`. Ключ формата `cp_live_<40 hex>`; показывается один раз при создании.
- `networks` — `code (unique), name, chain_id (nullable), confirmations_required int, is_enabled bool, explorer_tx_url, explorer_address_url, last_scanned_block bigint nullable, watcher_healthy bool, watcher_seen_at`.
- `token_contracts` — `network_code (fk networks.code), symbol ('USDT'|'USDC'), contract_address, decimals, is_enabled`; unique `(network_code, symbol)`.
- `wallets` — `network_code unique, next_index int default 0, xpub text nullable, derivation_path (default `m/44'/60'/0'/0`, для tron `m/44'/195'/0'/0`), label nullable, xpub_set_at nullable, xpub_set_by nullable fk users`. `xpub` — только публичный ключ, наружу отдаётся исключительно маской (SPEC §3).
- `deposit_addresses` — `network_code, address, derivation_index, merchant_id nullable, invoice_id nullable, is_active`; unique `(network_code, address)`. Адрес привязывается к счёту навсегда (переиспользование не делаем).
- `invoices` — `merchant_id, type ('payment'|'token_purchase'), external_id nullable, currency ('USDT'|'USDC'), network_code, deposit_address_id, amount (к оплате), amount_received (сумма detected+confirmed), amount_confirmed, status, description, customer_email, customer_id (строка мерчанта), metadata json, success_url, cancel_url, expires_at, paid_at, timestamps`. Индексы: `(merchant_id, external_id)`, `status`, `expires_at`.
- `transactions` — `invoice_id nullable, deposit_address_id, merchant_id nullable, network_code, tx_hash, log_index int default 0, from_address, to_address, currency, contract_address, amount, amount_raw string, block_number bigint, block_hash, confirmations int, status ('detected'|'confirmed'|'failed'|'orphaned'), credited_at nullable, raw json`; unique `(network_code, tx_hash, log_index)`.
- `balances` — `merchant_id, currency, network_code, available, pending`; unique `(merchant_id, currency, network_code)`.
- `ledger_entries` — `merchant_id, currency, network_code, amount (signed), type ('deposit'|'adjustment'), transaction_id nullable, balance_after, note`.
- `tokens` — товар token sale: `merchant_id, symbol, name, description, price_usd decimal(36,18), decimals int (default 18), total_supply (nullable), sold decimal, min_purchase, max_purchase nullable, is_active, image_url`.
- `token_purchases` — `invoice_id unique, token_id, merchant_id, customer_id, customer_email, token_amount, price_usd, pay_amount, currency, status ('pending'|'completed'|'expired'|'cancelled'), completed_at`.
- `token_holdings` — `token_id, merchant_id, customer_id, amount`; unique `(token_id, customer_id)`.
- `webhook_deliveries` — `merchant_id, invoice_id nullable, event, url, payload json, signature, attempts int, max_attempts int 6, status ('pending'|'delivered'|'failed'), response_code, response_body (truncated 2k), next_attempt_at, delivered_at, last_error`.
- `audit_logs` — `user_id, action, subject_type, subject_id, changes json, ip`.

## 5. Статусы счёта (invoice)

`pending` → (первая tx обнаружена) → `confirming` → (подтверждено ≥ amount) → `paid`  
`pending`/`confirming` → (истёк expires_at, ничего не получено) → `expired`  
`confirming` → (подтверждено < amount после истечения) → `partially_paid`  
`paid` при подтверждённой сумме > amount → `overpaid` (считается оплаченным; `is_paid = status in [paid, overpaid]`)  
`pending` → API/админ → `cancelled`

Оплата на адрес **истёкшего/отменённого** счёта всё равно зачисляется мерчанту (ledger `deposit`), а счёт переводится в `paid`/`partially_paid` с вебхуком — "late payment".

Допуск недоплаты: `underpayment_tolerance` в настройках мерчанта (settings json), по умолчанию `0.5` (%), если недоплата в пределах допуска — `paid`.

Зачисление на баланс: **только по `confirmed` транзакции**, ровно один раз (`credited_at`). `pending` в балансе = сумма detected‑транзакций, ещё не подтверждённых.

## 6. HTTP API (backend, Laravel)

Base URL: `http://localhost:8095`. Ответы JSON. Ошибки:
```json
{ "error": { "code": "validation_error", "message": "...", "details": {"amount": ["..."]} } }
```
Коды: `unauthenticated` (401), `forbidden` (403), `not_found` (404), `validation_error` (422), `invalid_state` (409), `rate_limited` (429), `server_error` (500).

### 6.1 Merchant API — `/api/v1/*`
Auth: `Authorization: Bearer cp_live_...`. Rate limit 120 req/min на ключ. Идемпотентность создания: заголовок `Idempotency-Key` (хранить в cache 24h → тот же ответ).

**Объект Invoice** (везде одинаковый):
```json
{
  "id": "uuid", "type": "payment", "external_id": "order-1", "status": "pending", "is_paid": false,
  "selection_required": false,
  "currency": "USDT", "network": "tron",
  "amount": "100.000000", "amount_received": "0", "amount_confirmed": "0",
  "address": "T...", "payment_url": "http://localhost:8095/pay/{id}",
  "qr_payload": "T..." ,
  "description": "...", "customer_email": null, "customer_id": null, "metadata": {},
  "success_url": null, "cancel_url": null,
  "expires_at": "ISO8601", "paid_at": null, "created_at": "ISO8601",
  "transactions": [ { "tx_hash": "...", "amount": "...", "confirmations": 3, "confirmations_required": 19, "status": "detected", "explorer_url": "...", "created_at": "..." } ],
  "token_purchase": null
}
```
`qr_payload`: для EVM — `ethereum:{contract}@{chainId}/transfer?address={address}&uint256={amount_raw}` (EIP‑681), для Tron — просто адрес.

**Отложенный выбор сети.** `currency` и `network` необязательны и задаются **только парой**. Если их не передать, счёт создаётся в `pending` с `currency = network = address = qr_payload = null` и `selection_required: true` — валюту и сеть выбирает плательщик на hosted checkout (§6.3), и именно выбор выделяет депозитный адрес. Суммы номинированы в USD (USDT = USDC = 1 USD), поэтому `amount` от сети не зависит и при выборе не меняется. Невыбранный счёт истекает как обычный. `selection_required` = `deposit_address_id is null && status = pending && expires_at не прошёл`.

- `POST /api/v1/invoices` — body: `amount` (required, >0), `currency` (USDT|USDC, optional), `network` (ethereum|bsc|tron, optional; либо обе, либо ни одной — одна без второй = 422), `external_id?`, `description?`, `customer_email?`, `customer_id?`, `metadata?` (object), `success_url?`, `cancel_url?`, `expires_in?` (сек, default 3600, max 86400). → 201 Invoice.
- `POST /api/v1/invoices/{id}/select` — body `{ "currency", "network" }` → Invoice. Мерчант‑сторонний двойник §6.3: то же поведение и те же коды ошибок, для мерчанта с собственным чекаутом.
- `GET /api/v1/invoices` — фильтры `status`, `external_id`, `network`, `currency`, `from`, `to`, `per_page` (≤100). Пагинация Laravel (`data`, `meta`).
- `GET /api/v1/invoices/{id}` → Invoice.
- `POST /api/v1/invoices/{id}/cancel` → Invoice (только из `pending`, иначе 409).
- `GET /api/v1/networks` → `[ { "code", "name", "chain_id", "confirmations_required", "tokens": [ { "symbol", "contract_address", "decimals" } ] } ]` (только enabled).
- `GET /api/v1/balances` → `[ { "currency", "network", "available", "pending" } ]` + `totals` по currency.
- `GET /api/v1/transactions` — фильтры `invoice_id`, `network`, `status`, пагинация.
- `GET /api/v1/tokens` — токены мерчанта (active).
- `POST /api/v1/token-purchases` — `token_id`, одно из `token_amount` | `pay_amount`, `currency` (optional), `network` (optional; пара — как у счетов), `customer_id` (required), `customer_email?`, `external_id?`, `success_url?`, `cancel_url?`, `metadata?`, `expires_in?` → 201 `{ "purchase": {...}, "invoice": Invoice }`. Цена фиксируется на момент создания; `pay_amount = token_amount * price_usd` (USDT/USDC считаем = 1 USD).
- `GET /api/v1/token-purchases/{id}`; `GET /api/v1/token-purchases?customer_id=`.
- `GET /api/v1/customers/{customer_id}/holdings` → `[ { "token": {...}, "amount": "..." } ]`.
- `GET /api/v1/me` → мерчант + webhook settings (без секрета).

### 6.2 Webhooks (исходящие, backend → мерчант)
`POST {merchant.webhook_url}`, `Content-Type: application/json`, заголовки:
`X-CryptoPay-Event`, `X-CryptoPay-Delivery` (uuid), `X-CryptoPay-Timestamp` (unix), `X-CryptoPay-Signature: sha256=<hex hmac_sha256(webhook_secret, timestamp + "." + raw_body)>`.
Body: `{ "id": delivery_uuid, "event": "invoice.paid", "created_at": ISO, "data": { "invoice": Invoice, "token_purchase": {...}|null } }`.
События: `invoice.confirming`, `invoice.paid`, `invoice.overpaid`, `invoice.partially_paid`, `invoice.expired`, `invoice.cancelled`, `token_purchase.completed`.
Успех = 2xx. Ретраи: 1m, 5m, 30m, 2h, 6h, 24h (6 попыток) через очередь `webhooks`. Ручной повтор из админки.

### 6.3 Public (hosted checkout, без auth) — `/api/public/*`
Rate limit 120 req/min на IP на весь префикс. `{id}` обязан быть uuid, иначе 404.

- `GET /api/public/invoices/{id}` → Invoice без `metadata`, `customer_*`, плюс `network_name`, `explorer_address_url`, `token_contract`, `confirmations_required`, `selection_required` и `options`.
  `options` — непустой, только когда `selection_required: true`; иначе `[]`. Это enabled‑сети × enabled‑контракты (§2):
  ```json
  [ { "network": "tron", "network_name": "Tron", "chain_id": null, "currency": "USDT",
      "confirmations_required": 19, "standard": "TRC-20" } ]
  ```
  `standard` выводится из кода сети: ethereum → ERC‑20, bsc → BEP‑20, tron → TRC‑20.
- `POST /api/public/invoices/{id}/select` — body `{ "currency": "USDT|USDC", "network": "ethereum|bsc|tron" }` → 200 Invoice (тот же публичный объект, уже с `address`, `qr_payload`, `network_name` и `selection_required: false`).
  Разрешён, только пока счёт `pending`, адрес ещё не выделен и `expires_at` не прошёл; иначе 409 `invalid_state`. Пара вне списка `options` → 422. Выбор выделяет адрес через AddressService **не более одного раза на счёт**: строка счёта берётся под `lockForUpdate`, повторная проверка идёт внутри транзакции, поэтому двойной сабмит с чекаута не порождает второй адрес (второй запрос получает 409).
- Фронт поллит `GET` каждые 5с.

### 6.4 Admin API — `/api/admin/*`
Auth: Laravel Sanctum, `POST /api/admin/auth/login {email,password}` → `{ token, user }` (personal access token), затем `Authorization: Bearer <token>`. `POST /api/admin/auth/logout`, `GET /api/admin/auth/me`.
- `GET /api/admin/dashboard` → `{ stats: { invoices_total, invoices_paid, volume_24h: {USDT, USDC}, volume_total, merchants_active, pending_webhooks }, chart: [ {date, USDT, USDC} ] (30 дней), recent_invoices: [...], networks: [ {code, is_enabled, watcher_healthy, last_scanned_block, watcher_seen_at} ] }`.
- Merchants: `GET /merchants`, `POST /merchants`, `GET /merchants/{id}` (включая balances, api_keys, webhook_url), `PUT /merchants/{id}`, `POST /merchants/{id}/api-keys {name}` → `{ key: "cp_live_..." (один раз), api_key: {...} }`, `DELETE /merchants/{id}/api-keys/{keyId}` (revoke), `POST /merchants/{id}/webhook-secret/rotate`.
- Invoices: `GET /invoices` (фильтры как в v1 + `merchant_id`, `q` по id/external_id/address), `GET /invoices/{id}` (с transactions, webhooks), `POST /invoices/{id}/cancel`, `POST /invoices/{id}/simulate-payment {amount?, confirmed?: bool}` — только если `SIMULATION_ENABLED=true`: создаёт фейковую транзакцию через тот же pipeline, что и watcher (для демо/QA).
- Transactions: `GET /transactions` (фильтры), `GET /transactions/{id}`.
- Networks: `GET /networks` (+token_contracts), `PUT /networks/{code} {confirmations_required, is_enabled, explorer_*}`, `PUT /networks/{code}/tokens/{symbol} {contract_address, decimals, is_enabled}`.
- Wallets (SPEC §3) — «куда идут деньги»; чтение доступно роли `viewer`, мутации только `admin`:
  - `GET /wallets` → `{ data: [ { network, network_name, standard, source ('database'|'env'|'none'), configured,
    xpub_masked (первые 10 + '…' + последние 6, либо null), derivation_path, label, xpub_set_at,
    xpub_set_by {id,name}|null, next_index, addresses_issued,
    last_address {address, derivation_index, explorer_url, created_at}|null,
    received { USDT, USDC } (сумма зачисленных deposit‑записей `ledger_entries` по сети, по всем мерчантам),
    explorer_address_url } ] }` — порядок ethereum, bsc, tron.
  - `POST /wallets/{network}/preview {xpub}` → `{ addresses: [ {index, path, address} ] }` (первые 5). Ничего не сохраняет;
    валидацию ключа выполняет watcher (`POST /addresses/derive-batch`), его `422 invalid_xpub` превращается в
    `422 validation_error` с `details.xpub`.
  - `PUT /wallets/{network} {xpub, label?, apply_to_evm?}` → `{ data: {...тот же объект...} }`. `apply_to_evm` для
    ethereum/bsc сохраняет ключ в обе EVM‑сети. Если адреса уже выдавались, в ответе появляется поле `warning`
    (UI обязан показать подтверждение). Пишет `audit_logs` (`wallet.xpub_updated`) **только с маской**.
  - `DELETE /wallets/{network}/xpub` → очищает `wallets.xpub` (возврат к env), audit `wallet.xpub_removed`.
  - `GET /wallets/{network}/addresses?page=&per_page=` → пагинированный список `deposit_addresses` сети:
    `{ id, address, derivation_index, network, invoice_id, merchant {id,name}|null, received {USDT,USDC}
    (подтверждённые транзакции на этот адрес), explorer_url, is_active, created_at }`.
- Tokens: CRUD `/tokens`, `GET /token-purchases`, `GET /tokens/{id}/holdings`.
- Webhooks: `GET /webhooks` (фильтры merchant_id, status), `POST /webhooks/{id}/retry`.
- Ledger/balances: `GET /balances?merchant_id=`, `GET /ledger?merchant_id=`.
- Users: CRUD `/users` (только role admin).
- `GET /watcher/health` — прокси к watcher `/health`.

### 6.5 Internal API (watcher ↔ backend) — `/api/internal/*`
Auth: заголовок `X-Internal-Token: {INTERNAL_API_TOKEN}` (общий секрет из env, placeholder отвергается → 503). Доступен **только** через внутренний порт nginx `8081` (не публикуется наружу); на публичном порту эти пути отдают 404. Все запросы идемпотентны.
- `GET /api/internal/config` →
  ```json
  { "networks": [ { "code": "ethereum", "chain_id": 1, "confirmations_required": 12, "is_enabled": true,
      "last_scanned_block": 123, "tokens": [ { "symbol": "USDT", "contract_address": "0x...", "decimals": 6 } ] } ] }
  ```
- `GET /api/internal/watch-addresses?network=ethereum&updated_since=ISO` → `{ "addresses": [ { "id": "uuid", "address": "0x..." } ] }` (все `is_active` адреса сети; watcher кеширует и обновляет каждые 10с).
- `POST /api/internal/transactions` — одна tx (upsert по `(network, tx_hash, log_index)`):
  ```json
  { "network": "ethereum", "tx_hash": "0x..", "log_index": 5, "contract_address": "0x..", "symbol": "USDT",
    "from_address": "0x..", "to_address": "0x..", "amount_raw": "100000000", "amount": "100",
    "block_number": 123, "block_hash": "0x..", "confirmations": 1, "status": "detected", "raw": {} }
  ```
  → `{ "ok": true, "transaction_id": "uuid", "invoice_id": "uuid|null" }`. Статусы: `detected` | `confirmed` | `orphaned` (reorg). Повторная отправка с бóльшим `confirmations` обновляет запись; переход в `confirmed` запускает зачисление.
- `POST /api/internal/transactions/batch` — `{ "transactions": [ ...как выше ] }`.
- `POST /api/internal/heartbeat` — `{ "network": "bsc", "last_scanned_block": 123, "head_block": 130, "healthy": true, "error": null }` → обновляет `networks.last_scanned_block/watcher_*`.

### 6.6 Watcher HTTP (порт 3100, внутри compose `http://watcher:3100`)
Auth: тот же `X-Internal-Token`.
- `GET /health` → `{ "ok": true, "networks": [ { "code", "enabled", "headBlock", "lastScannedBlock", "lag", "pendingTxs", "lastError", "updatedAt" } ] }` (без auth).
- `POST /addresses/derive` `{ "network": "tron", "index": 5, "xpub"? }` → `{ "network", "index", "path": "m/44'/195'/0'/0/5", "address": "T..." }`.
- `POST /addresses/derive-batch` `{ "network", "from": 0, "count": 10, "xpub"? }` → `{ "addresses": [ {index, path, address} ] }`.
  При наличии `xpub` в теле деривация идёт от него (приоритет над env-`EVM_XPUB`/`TRON_XPUB`,
  работает и когда соответствующий env-xpub не задан); невалидный `xpub` → `422 invalid_xpub`.
  xpub нигде не логируется и не попадает в ответ при ошибке.
- `POST /rescan` `{ "network", "from_block" }` — принудительный пересмотр диапазона.

## 7. Алгоритм watcher

Общий цикл на сеть (независимые воркеры, `setInterval`/loop с backoff при ошибках RPC):
1. Стартовая точка: `last_scanned_block` из `/api/internal/config` (или локальный state `data/state.json`), если null — `head - 1`.
2. EVM (ethers v6, `JsonRpcProvider`): для диапазона `[from, min(head - 0, from + BATCH)]` вызвать `getLogs({ address: [contracts], topics: [Transfer, null, paddedTo[]] })`; список `to` разбивать на чанки по 500. Декодировать `value`, посчитать `amount = value / 10^decimals`. Отправить `detected` с `confirmations = head - blockNumber + 1`.
3. Tron (TronGrid HTTP, `TRON_API_KEY` опционально): `wallet/getnowblock` → head; `wallet/getblockbynum` для каждого блока (параллельно по 4; `getblockbylimit` на TronGrid больше не поддерживается); фильтр `raw_data.contract[0].type == 'TriggerSmartContract'`, `contract_address` ∈ token contracts (hex), `data` начинается с `a9059cbb`, `ret[0].contractRet == 'SUCCESS'`; `to = 41 + data[32..72]` → base58, `amount = BigInt('0x'+data[72..136])`.
4. Подтверждения: watcher держит список незавершённых tx (в памяти + state‑файл). На каждом новом head пересчитывает `confirmations` и шлёт batch‑обновления при изменении; когда `confirmations >= required` — для EVM проверяет `getTransactionReceipt` (status == 1 и blockHash совпадает) → `confirmed`, иначе `orphaned`. Для Tron — подтверждение, когда блок ≤ `walletsolidity/getnowblock` и `gettransactioninfobyid.receipt.result == 'SUCCESS'`.
5. Heartbeat каждые 15с. Лаг > 50 блоков или ошибка RPC 3 раза подряд → `healthy=false`.
6. Reorg‑защита EVM: хранить hash последних 64 блоков; если parentHash не совпадает — откатиться на 64 блока назад и пересканировать.

## 8. Переменные окружения (единый `.env` в корне для compose)

```
APP_NAME=CryptoPay
APP_URL=http://localhost:8095
APP_KEY=                        # генерируется entrypoint'ом, если пусто
APP_ENV=local
APP_DEBUG=true
SIMULATION_ENABLED=true         # разрешить /simulate-payment в админке (выключить в проде)

DB_HOST=postgres DB_PORT=5432 DB_DATABASE=cryptopay DB_USERNAME=cryptopay DB_PASSWORD=secret
REDIS_HOST=redis
INTERNAL_API_TOKEN=change-me-internal-token
WATCHER_URL=http://watcher:3100
BACKEND_URL=http://nginx:8081   # для watcher: internal API живёт на отдельном непубликуемом порту nginx

ADMIN_EMAIL=admin@cryptopay.local
ADMIN_PASSWORD=password

ETH_RPC_URL=https://ethereum-rpc.publicnode.com
BSC_RPC_URL=https://bsc-rpc.publicnode.com
TRON_API_URL=https://api.trongrid.io
TRON_API_KEY=
EVM_XPUB=                       # из `npm run keygen`
TRON_XPUB=
WATCHER_ENABLED=true            # false = watcher только деривация, без сканирования
```

## 9. Frontend (Vue 3)

Тема: светлая, тёплая (`color-scheme: light`, на `<html>` нет класса `dark`). Токены Tailwind (`frontend/tailwind.config.js`):

| Токен | Значение | Где используется |
| --- | --- | --- |
| `bg` | `#f6f5f0` | тёплый фон страницы |
| `surface` | `#ffffff` | карточки, сайдбар, топбар, модалки, дровер, тосты |
| `surface-2` | `#f1efe8` | шапки таблиц, блоки кода, вложенные панели, hover строки |
| `border` / `border-strong` | `#e5e2d9` / `#d6d2c6` | рамки карточек / рамки инпутов и вторичных кнопок |
| `text` / `muted` | `#1c1b1f` / `#63616c` | основной / вторичный текст |
| `primary` / `primary-hover` | `#6d4df2` / `#5a3bdc` | заливка кнопок / ссылки и текст по мягкому фону |
| `primary-soft` / `primary-on` | `#efeaff` / `#ffffff` | активный пункт навигации, чипы / текст на заливке |
| `accent` / `accent-ink` / `accent-soft` | `#0ea5a4` / `#0f6f6e` / `#e2f7f6` | вторая серия графика / текст акцентом / мягкий фон |
| `success` / `success-soft` | `#136c33` / `#e6f6ec` | статусы, бейджи |
| `warning` / `warning-soft` | `#a24a08` / `#fdf3e1` | предупреждения |
| `danger` / `danger-soft` | `#b91c1c` / `#fdeaea` | ошибки, деструктивные действия |
| `info` / `info-soft` | `#1d4ed8` / `#e8efff` | информационные callout'ы |

Фокус-кольцо — `primary` с альфой 40%. Тень карточки — `0 1px 2px rgba(20,16,40,.06), 0 8px 24px rgba(20,16,40,.06)`; всплывающие поверхности используют более плотную `shadow-pop`. Оверлей модалок/дровера — `rgba(28,27,31,.35)`. Скелетоны — `#ecebe5`.

`success` и `warning` на шаг темнее исходных `#15803d` / `#b45309`: на своих мягких подложках те давали 4.48 и 4.56 — на грани WCAG AA. Аналогично `muted` темнее `#6f6d78` (4.41 на `surface-2`). Все текстовые пары проверены на AA (4.5:1 для основного текста, 3:1 для крупного).

Шрифт Inter / system, моно для адресов и хешей.

Логотипы сетей и токенов — Vue-компоненты в `frontend/src/components/icons/` (`EthereumLogo`, `BnbLogo`, `TronLogo`, `UsdtLogo`, `UsdcLogo`), собранные из CC0-набора в `src/assets/crypto/*.svg`. Диспетчер `icons/CryptoLogo.vue` принимает `kind` (`ethereum` | `bsc` | `tron` | `USDT` | `USDC`); обёртки — `NetworkIcon.vue` и `CoinLogo.vue`. SVG инлайнится как шаблон компонента: ни `v-html`, ни внешних URL — CSP `img-src` не задействован.

Роуты: `/login`; `/admin` (dashboard), `/admin/merchants`, `/admin/merchants/:id`, `/admin/invoices`, `/admin/invoices/:id`, `/admin/transactions`, `/admin/networks`, `/admin/tokens`, `/admin/tokens/:id`, `/admin/webhooks`, `/admin/users`, `/admin/docs` (документация merchant API с примерами curl/PHP/JS и описанием подписи вебхуков); публичная `/pay/:id` — hosted checkout (сумма, сеть/токен, адрес с копированием, QR, таймер, прогресс подтверждений, статусы, кнопка success_url при оплате; при `selection_required` — шаг выбора валюты и сети из `options` с подтверждениями и оценкой времени, после `POST .../select` адрес появляется без перезагрузки).

Все запросы через `/api` (тот же origin, проксирует nginx). Токен админа — в `localStorage`, axios interceptor, редирект на `/login` при 401.

## 10. Docker

- `app`: `php:8.4-fpm-alpine` + `pdo_pgsql bcmath gmp pcntl redis(pecl)` + composer install; entrypoint: ждёт postgres, `php artisan key:generate` если пуст, `migrate --force`, `db:seed --force` (идемпотентно), `optimize`.
- `queue`: тот же образ, `php artisan queue:work redis --queue=default,webhooks --tries=3`.
- `scheduler`: тот же образ, `php artisan schedule:work` (expire invoices каждую минуту, retry webhooks, health).
- `nginx`: multi‑stage — `node:22-alpine` собирает `frontend/` → `nginx:alpine` отдаёт `dist/` и проксирует `/api` → `app:9000` (fastcgi, root `backend/public`).
- `watcher`: `node:22-alpine`, volume `watcher-data:/app/data`.
- Порт наружу: `8095` (APP_PORT в .env).
