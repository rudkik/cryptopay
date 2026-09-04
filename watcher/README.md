# CryptoPay watcher

Микросервис на Node 22 + TypeScript. Отвечает за три вещи (SPEC §3, §7):

1. **Деривация депозитных адресов** из account-level xpub (приватных ключей сервис не хранит).
2. **Сканирование сетей** Ethereum / BSC (ERC-20/BEP-20 через JSON-RPC) и Tron (TRC-20 через TronGrid)
   на входящие переводы USDT/USDC на отслеживаемые адреса.
3. **Отслеживание подтверждений и реоргов** с отчётами в Laravel-backend по внутреннему API.

Приватных ключей нет ни в одном рантайм-сервисе: мнемоника генерируется `npm run keygen`
и хранится офлайн, в `.env` попадают только `EVM_XPUB` / `TRON_XPUB`.

## Как это работает

```
        ┌──────────── каждые 60с: GET /api/internal/config
        │             (сети, контракты, decimals, confirmations_required, last_scanned_block)
        │
        ├──────────── каждые 10с: GET /api/internal/watch-addresses?network=
        │             (кешируется в Set; EVM в нижнем регистре, Tron — base58 + карта hex→base58)
        │
Laravel │             POST /api/internal/transactions        ← detected (сразу)
backend ├──────────── POST /api/internal/transactions/batch  ← обновления подтверждений / confirmed / orphaned
        │
        └──────────── каждые 15с: POST /api/internal/heartbeat (head, last_scanned, healthy, error)
```

### Цикл сканирования EVM

1. `head = getBlockNumber()`. Стартовая точка — `last_scanned_block` из конфига или локального
   `data/state.json` (что дальше), при пустом значении — `head - 1`.
2. **Реорг-защита:** хранятся хеши последних 64 блоков. Если хеш последнего просканированного
   блока в цепочке изменился — откат на 64 блока назад, пересканирование и перепроверка
   зависших транзакций (не подтвердившиеся уходят в `orphaned`).
3. `getLogs({ address: [контракты], topics: [Transfer, null, paddedTo[]] })` на диапазон
   `[from, min(head, from + EVM_BATCH_BLOCKS - 1)]`. Список `to` бьётся на чанки по 500 адресов.
   Если RPC ругается на слишком большой диапазон — размер батча делится пополам и постепенно
   восстанавливается до `EVM_BATCH_BLOCKS` после успешных проходов.
4. Новые переводы уходят как `detected` немедленно.

### Цикл сканирования Tron

1. `wallet/getnowblock` → head, `walletsolidity/getnowblock` → solidity head.
2. За один проход берётся не более **10 блоков** (`TRON_MAX_BLOCKS_PER_CALL`), и каждый
   запрашивается **отдельным** `wallet/getblockbynum`: TronGrid больше не поддерживает
   `wallet/getblockbylimit` (HTTP 405). Между запросами пауза 250 мс, на HTTP 429 — до 4
   ретраев с экспоненциальным backoff (1 с → 8 с). Нода обязана вернуть ровно
   запрошенную высоту, иначе ответ отбрасывается. Курсор `last_scanned_block` двигается
   **только по фактически полученному непрерывному префиксу** — недополученные блоки
   не считаются просканированными.
3. Из блока берутся транзакции с `ret[0].contractRet === 'SUCCESS'`,
   `raw_data.contract[0].type === 'TriggerSmartContract'`, hex-адресом контракта из конфига
   и `data`, начинающимся с `a9059cbb` (`transfer`) или `23b872dd` (`transferFrom`).
   Аргумент-адрес в ABI-слове встречается в двух валидных формах: EVM-стиль
   (12 нулевых байт + 20 байт адреса) и Tron-стиль (11 нулевых байт + `0x41` + 20 байт);
   обе декодируются в `41…` → base58. `amount = BigInt` последнего слова.
   Переводы, сделанные внутри вызова другого контракта (internal transactions),
   в блоке как `TriggerSmartContract` не видны — это известное ограничение SPEC §7.3.

### Подтверждения

Единый автомат (`src/scanner/tracker.ts`), общий для обеих семей сетей:

```
detected(1) ──► detected(N) ──► [N >= confirmations_required] ──► verify ──┬─► confirmed
                                                                            └─► orphaned
```

* `confirmations = min(head - block_number + 1, confirmations_required)`.
* Обновление в backend уходит **только если счётчик реально изменился** и **не чаще одного раза
  на новый head**.
* Проверка при достижении порога: EVM — `getTransactionReceipt` (`status === 1` и совпадение
  `blockHash`), Tron — блок ≤ solidity head и `gettransactioninfobyid.receipt.result === 'SUCCESS'`.
* Неопределённый результат (нода ещё не отдаёт receipt) не теряет транзакцию: она остаётся
  в pending и перепроверяется на следующем head.

### Состояние и устойчивость

* `data/state.json` (каталог из `DATA_DIR`) хранит `last_scanned_block`, pending-транзакции и
  хеши последних блоков. Запись атомарная: временный файл + `rename`.
* Если каталог недоступен на запись (типично для docker volume, созданного от root), сервис
  **не падает**: пишет понятную ошибку в лог, `/health` показывает `statePersistent: false`
  и продолжает работать без персистентности.
* Ошибки RPC/backend никогда не роняют цикл: они логируются, увеличивают счётчик и уводят сеть
  в `healthy=false` (3 ошибки подряд либо лаг > 50 блоков).
* **Режим догона.** Пауза между проходами воркера подстраивается под ситуацию: при лаге > 1
  блока и без ошибок она сжимается до 250 мс (сеть догоняется без ожидания полного
  `POLL_INTERVAL_MS` (потолок; дефолты по сети: bsc 1500 мс, tron 3000, ethereum 5000; `POLL_INTERVAL_MS_<NETWORK>` переопределяет)), при ошибке RPC растёт экспоненциально до 60 с, а на ровном ходу
  возвращается к `POLL_INTERVAL_MS`.
* **Редактирование секретов** (`src/redact.ts`). При загрузке конфига `INTERNAL_API_TOKEN`,
  `TRON_API_KEY`, `EVM_XPUB` и `TRON_XPUB` регистрируются как секреты и вырезаются из текстов
  по значению; любой URL сворачивается до `scheme://host` (+ `/***`, если были путь или query),
  так что `https://mainnet.infura.io/v3/<KEY>` не утекает; сериализованный extended key
  ловится ещё и по форме (`xpub…`/`ypub…`/`zpub…` и приватные варианты) — это покрывает
  ad-hoc `xpub` из тела `/addresses/derive(-batch)`, который в реестр намеренно не попадает.
  Фильтр применяется и к сообщению об ошибке сети (`ScannerHealthState`), и к `lastError`
  в неаутентифицированном `/health`.
* Backend может подниматься дольше watcher'а — стартовый запрос конфига ретраится с backoff
  бесконечно, HTTP-сервер при этом уже слушает порт.
* `SIGTERM` / `SIGINT` — graceful shutdown с финальным сбросом состояния на диск.

## Установка и команды

```bash
npm install
npm run build      # tsc -> dist/
npm start          # node dist/index.js
npm run dev        # tsx watch src/index.ts
npm test           # vitest run
npm run typecheck  # tsc --noEmit
npm run keygen     # node dist/keygen.js  (требует npm run build)
npm run keygen:dev # tsx src/keygen.ts    (без сборки, для локальной разработки)
```

### Генерация ключей

```bash
npm run build && npm run keygen            # 12 слов
npm run keygen -- --words 24               # 24 слова
npm run keygen -- --mnemonic "word1 ..."   # пересчитать xpub из существующей мнемоники
```

Печатает мнемонику, `EVM_XPUB`, `TRON_XPUB` и первые 3 адреса каждой сети для сверки.
Команда полностью офлайновая: ей не нужны ни `BACKEND_URL`, ни уже заданные xpub.
В docker: `docker compose run --rm --no-deps watcher npm run keygen`.

> `ethereum` и `bsc` используют общий xpub (`m/44'/60'/0'`), поэтому адреса по одинаковым
> индексам совпадают — это ожидаемо (SPEC §3), индексы в БД ведутся по сетям раздельно.

## HTTP API (порт 3100)

Аутентификация — заголовок `X-Internal-Token` (сравнение за постоянное время).
Ошибки в общем конверте: `{ "error": { "code", "message", "details"? } }`.

### `GET /health` — без аутентификации

```json
{
  "ok": true,
  "watcherEnabled": true,
  "derivationReady": true,
  "derivation": { "evm": true, "tron": true },
  "statePersistent": true,
  "networks": [
    { "code": "ethereum", "enabled": true, "headBlock": 21000000, "lastScannedBlock": 20999998,
      "lag": 2, "pendingTxs": 1, "lastError": null, "updatedAt": "2026-01-01T00:00:00.000Z" }
  ]
}
```

### `POST /addresses/derive`

```bash
curl -X POST http://watcher:3100/addresses/derive \
  -H 'X-Internal-Token: ...' -H 'Content-Type: application/json' \
  -d '{"network":"tron","index":5}'
```
```json
{ "network": "tron", "index": 5, "path": "m/44'/195'/0'/0/5", "address": "T..." }
```

Необязательное поле `xpub` (строка) в теле запроса переопределяет env-конфигурацию:
если оно задано — деривация идёт от него, а не от `EVM_XPUB`/`TRON_XPUB`, и работает
даже если соответствующий env-xpub вовсе не настроен. Backend-админка (страница Wallet)
именно так и подставляет xpub из БД для превью адресов; env остаётся резервным путём,
когда тело запроса `xpub` не передаёт. Невалидный `xpub` (не строка, пустой, не с
префиксом `xpub`, битый base58check-чексум или не account-level, т.е. `depth != 3`) —
`422 invalid_xpub`. Сам xpub никогда не попадает ни в ответ, ни в логи.

### `POST /addresses/derive-batch`

`{"network":"ethereum","from":0,"count":10,"xpub":"xpub..."?}` → `{ "addresses": [ { index, path, address } ] }`
(`count` — от 1 до 100). `xpub` необязателен и работает так же, как и у `/addresses/derive`
(приоритет над env, `422 invalid_xpub` при невалидном значении).

### `POST /rescan`

`{"network":"ethereum","from_block":21000000}` — принудительный пересмотр диапазона.
Отчёты идемпотентны (upsert по `(network, tx_hash, log_index)`), повторный проход безопасен.

### Коды ошибок

| Код                | HTTP | Когда                                                     |
|--------------------|------|-----------------------------------------------------------|
| `unauthenticated`  | 401  | нет или неверный `X-Internal-Token`                        |
| `validation_error` | 422  | некорректное тело запроса                                  |
| `invalid_state`    | 409  | `/rescan` при `WATCHER_ENABLED=false` или неактивной сети  |
| `not_found`        | 404  | неизвестный маршрут                                        |
| `xpub_missing`     | 503  | `EVM_XPUB` / `TRON_XPUB` не заданы, и тело `xpub` не передано |
| `invalid_xpub`     | 422  | `xpub` в теле `/addresses/derive(-batch)` невалиден         |
| `server_error`     | 500  | внутренняя ошибка                                          |

Без xpub сервис **всё равно стартует**: `/health` отдаёт `derivationReady: false`,
а `/addresses/*` — `503 xpub_missing`. При `WATCHER_ENABLED=false` сканирование не
запускается вовсе, работают только деривация и `/health`.

## Переменные окружения

| Переменная            | По умолчанию            | Назначение                                              |
|-----------------------|-------------------------|---------------------------------------------------------|
| `APP_ENV`             | `production`            | всё, кроме `local`/`dev`/`test`, включает строгую проверку секретов: с пустым или `change-me…` токеном процесс не стартует |
| `BACKEND_URL`         | `http://nginx`          | база внутреннего API Laravel                            |
| `INTERNAL_API_TOKEN`  | —                       | общий секрет, обязателен                                |
| `PORT`                | `3100`                  | порт HTTP-сервера                                       |
| `ETH_RPC_URL`         | —                       | JSON-RPC Ethereum                                       |
| `BSC_RPC_URL`         | —                       | JSON-RPC BSC                                            |
| `TRON_API_URL`        | `https://api.trongrid.io` | база TronGrid                                         |
| `TRON_API_KEY`        | пусто                   | если задан — уходит как `TRON-PRO-API-KEY`              |
| `EVM_XPUB`            | пусто                   | account xpub `m/44'/60'/0'`                             |
| `TRON_XPUB`           | пусто                   | account xpub `m/44'/195'/0'`                            |
| `WATCHER_ENABLED`     | `true`                  | `false` — только деривация, без сканирования            |
| `DATA_DIR`            | `./data`                | каталог `state.json`                                    |
| `LOG_LEVEL`           | `info`                  | уровень pino                                            |
| `EVM_BATCH_BLOCKS`    | `20`                    | блоков за один проход getLogs (адаптивно уменьшается)   |
| `POLL_INTERVAL_MS`    | `5000`                  | пауза между проходами воркера                           |

Необязательный тюнинг: `HOST`, `CONFIG_REFRESH_MS` (60000), `ADDRESS_REFRESH_MS` (10000),
`HEARTBEAT_MS` (15000), `REORG_DEPTH` (64), `MAX_LAG_BLOCKS` (50), `MAX_CONSECUTIVE_ERRORS` (3),
`BACKEND_RETRIES` (5), `REQUEST_TIMEOUT_MS` (30000). См. `.env.example`.

## Docker

Многостадийная сборка `node:22-alpine`: стадия build компилирует TypeScript, рантайм несёт
только прod-зависимости и `dist/`. Работает от пользователя `node`, healthcheck дёргает
`/health` через `wget`. `package.json` остаётся в образе — чтобы работал
`docker compose run --rm --no-deps watcher npm run keygen`.

Каталог `/app/data` создаётся и передаётся пользователю `node` **до** объявления `VOLUME`,
иначе именованный том создаётся от root и запись падает с `EACCES`.

## Структура

```
src/
  index.ts              точка входа: конфиг, HTTP, воркеры, graceful shutdown
  keygen.ts             CLI генерации мнемоники и xpub
  config.ts             разбор env
  logger.ts             pino
  amount.ts             raw -> человеко-читаемая строка
  redact.ts             реестр секретов и вырезание их из логов/ошибок/URL
  derivation.ts         HD-деривация EVM/Tron из xpub
  tronAddress.ts        base58check, hex <-> base58 (на примитивах ethers)
  types.ts              контракт watcher <-> backend
  backend/client.ts     клиент /api/internal/* с ретраями и backoff
  state/store.ts        атомарный state.json
  http/server.ts        fastify: /health, /addresses/*, /rescan
  scanner/
    manager.ts          обновление конфига/адресов, воркеры, heartbeat
    base.ts             общий учёт head/lastScanned/ошибок
    addresses.ts        кеш отслеживаемых адресов
    tracker.ts          автомат подтверждений (без сети, юнит-тестируемый)
    evm.ts              воркер Ethereum/BSC
    evmDecode.ts        разбор логов Transfer
    tron.ts             воркер Tron
    tronClient.ts       HTTP-клиент TronGrid
    tronDecode.ts       разбор блоков и данных TRC-20
test/                   vitest: деривация, декодирование EVM/Tron, автомат подтверждений,
                        EVM/Tron-сканеры, HTTP-сервер, hardening (редактирование секретов, лимиты)
```

## Тесты

```bash
npm test
```

Покрывают то, что можно проверить без сети: деривацию по известному вектору
(`test test ... junk` → `m/44'/60'/0'/0/0` = `0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266`)
и корректность Tron-адресов (34 символа base58, hex начинается с `41`, roundtrip
base58 → hex → base58), разбор лога `Transfer`, разбор собранного вручную блока Tron
с `TriggerSmartContract`, и автомат подтверждений целиком (рост счётчика, порог,
`confirmed` / `orphaned`, реорг, snapshot/restore).
