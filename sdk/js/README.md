# @cryptopay/sdk

Официальный TypeScript SDK для **CryptoPay** — сервиса приёма платежей в USDT/USDC
(Ethereum, BSC, Tron). Ноль рантайм-зависимостей, работает на встроенных `fetch` и
`node:crypto` из Node.js.

## Установка

```bash
npm i @cryptopay/sdk
```

## Требования

- Node.js **>= 18** (используются глобальный `fetch` и `AbortSignal.timeout`).
- Пакет собран в форматах ESM и CJS — подключайте как через `import`, так и через `require`.

## Быстрый старт

```ts
import { CryptoPay } from '@cryptopay/sdk'

const cryptoPay = new CryptoPay({
  apiKey: process.env.CRYPTOPAY_API_KEY!,   // cp_live_...
  baseUrl: 'https://pay.example.com',        // с /api/v1 или без — SDK нормализует сам
})

const invoice = await cryptoPay.createInvoice({
  amount: '12.50',
  currency: 'USDT',
  network: 'tron',
  external_id: 'order-42',
})

console.log(invoice.payment_url)
```

Также поддерживается позиционная форма конструктора:

```ts
const cryptoPay = new CryptoPay(process.env.CRYPTOPAY_API_KEY!, 'https://pay.example.com')
```

## Создание счёта и редирект на `payment_url`

После создания счёта редиректите покупателя на `invoice.payment_url` — это хостед-страница
оплаты CryptoPay (QR-код + адрес для перевода). Ваш `success_url`/`cancel_url` в теле запроса
определяют, куда покупатель вернётся после оплаты/отмены:

```ts
const invoice = await cryptoPay.createInvoice({
  amount: '99.90',
  currency: 'USDC',
  network: 'bsc',
  customer_email: 'buyer@example.com',
  success_url: 'https://shop.example.com/checkout/success',
  cancel_url: 'https://shop.example.com/checkout/cancel',
  expires_in: 1800, // секунд, максимум 86400
})

// res.redirect(invoice.payment_url)
```

Статус счёта финализируется асинхронно (через сеть) — опирайтесь на вебхуки
(см. ниже) или на поллинг `getInvoice(invoice.id)`, а не на редирект как на факт оплаты.

## Обработка ошибок

Все ошибки API — это экземпляры `ApiError` (наследуется от `CryptoPayError`, базового класса
для всех ошибок SDK, включая сетевые/таймаут):

```ts
import { ApiError, CryptoPayError } from '@cryptopay/sdk'

try {
  await cryptoPay.createInvoice({ amount: '0', currency: 'USDT', network: 'tron' })
} catch (err) {
  if (err instanceof ApiError) {
    if (err.isValidationError) {
      console.error('Ошибки валидации:', err.details) // { amount: [...] }
    } else if (err.isNotFound) {
      // 404
    } else if (err.isRateLimited) {
      // 429 — сделайте паузу и повторите
    }
    console.error(err.status, err.code, err.message)
  } else if (err instanceof CryptoPayError) {
    // сетевая ошибка / таймаут запроса
    console.error(err.message, err.cause)
  }
}
```

Коды ошибок API: `unauthenticated` (401), `forbidden` (403), `not_found` (404),
`validation_error` (422), `invalid_state` (409), `rate_limited` (429), `server_error` (500).

## Идемпотентность

`createInvoice` и `createTokenPurchase` принимают необязательный `idempotencyKey` вторым
аргументом. Передавайте его при ретраях (например, после таймаута), чтобы не создать
дублирующий счёт при повторной отправке того же запроса:

```ts
const invoice = await cryptoPay.createInvoice(params, `order-42-attempt-1`)
```

## Проверка вебхука вручную

CryptoPay подписывает тело каждого вебхука HMAC-SHA256. Проверяйте подпись **до** парсинга
тела и используйте именно "сырые" байты запроса (до любого JSON-парсинга фреймворком):

```ts
import { verifyWebhook, SignatureError } from '@cryptopay/sdk'

try {
  const event = verifyWebhook(rawBody, req.headers, process.env.CRYPTOPAY_WEBHOOK_SECRET!)

  if (event.isPaid) {
    // event.event === 'invoice.paid' | 'invoice.overpaid'
    await markOrderPaid(event.invoice!.external_id)
  }
} catch (err) {
  if (err instanceof SignatureError) {
    // невалидная подпись, протухший таймстамп, отсутствующие заголовки и т.п.
  }
}
```

`verifyWebhook(rawBody, headers, secret, options?)`:
- `rawBody` — `string | Buffer | Uint8Array`, сырое тело запроса.
- `headers` — объект заголовков (регистр не важен; значения-массивы берутся как `[0]`).
- `options.tolerance` — допустимое расхождение таймстампа в секундах (по умолчанию 300;
  `<= 0` отключает проверку — не рекомендуется в проде).

Пример полностью самостоятельного HTTP-сервера без Express — `examples/webhook-server.mjs`.

## Middleware для Express

```ts
import express from 'express'
import { cryptoPayWebhook } from '@cryptopay/sdk'

const app = express()

// ВАЖНО: сырое тело обязательно — не используйте express.json() на этом роуте.
app.post(
  '/webhooks/cryptopay',
  express.raw({ type: 'application/json' }),
  cryptoPayWebhook(process.env.CRYPTOPAY_WEBHOOK_SECRET!, async (event, req, res) => {
    if (event.isPaid) {
      await markOrderPaid(event.invoice!.external_id)
    }
    // если явно не ответить — middleware сам отправит 200 { received: true }
  }),
)
```

Если на роуте перед `cryptoPayWebhook` стоит `express.json()`, `req.body` окажется уже
распарсенным объектом, и middleware ответит `400` с понятным сообщением — обязательно
используйте `express.raw({ type: 'application/json' })` именно на роуте вебхука.

## Token sale / пополнение по `customer_id`

Продажа собственного токена мерчанта за USDT/USDC — покупатель идентифицируется вашим
внутренним `customer_id` (а не email), балансы токенов ведутся именно по нему:

```ts
const { purchase, invoice } = await cryptoPay.createTokenPurchase(
  {
    token_id: 'tok_123',
    token_amount: '200',       // либо pay_amount — сумма к оплате, взаимоисключимо с token_amount
    currency: 'USDT',
    network: 'tron',
    customer_id: 'user-42',
  },
  idempotencyKey,
)

// redirect на invoice.payment_url — оплата идёт как обычный счёт с type: 'token_purchase'
```

Обратите внимание: ответ `createTokenPurchase` **не обёрнут** в `{data: ...}` — это
`{purchase, invoice}` напрямую (в отличие от всех остальных методов SDK).

Холдинги покупателя:

```ts
const holdings = await cryptoPay.customerHoldings('user-42')
// [{ token, customer_id, amount, updated_at }, ...]
```

## Справочник методов

| Метод | Описание |
|---|---|
| `createInvoice(params, idempotencyKey?)` | Создать счёт на оплату |
| `getInvoice(id)` | Получить счёт по id |
| `listInvoices(filters?)` | Постраничный список счетов |
| `cancelInvoice(id)` | Отменить счёт |
| `networks()` | Список поддерживаемых сетей |
| `balances()` | Балансы мерчанта (`{ data, totals }`) |
| `transactions(filters?)` | Постраничный список on-chain транзакций |
| `tokens()` | Список токенов (token sale) |
| `getToken(id)` | Токен по id |
| `createTokenPurchase(params, idempotencyKey?)` | Создать покупку токенов (`{ purchase, invoice }`) |
| `getTokenPurchase(id)` | Покупка токенов по id |
| `listTokenPurchases(filters?)` | Постраничный список покупок токенов |
| `customerHoldings(customerId)` | Холдинги покупателя (без пагинации) |
| `me()` | Данные текущего мерчанта, балансы, настройки вебхука |

Плюс функции для работы с вебхуками: `verifyWebhook`, `signWebhook`, `cryptoPayWebhook`
(см. разделы выше).

## Важно: суммы — это строки

Все денежные суммы в API (и в объектах, которые возвращает SDK) — **decimal-строки**
(`"12.500000"`), а не `number`. Это сделано, чтобы не терять точность при работе с суммами
до 18 знаков после запятой. **Никогда не приводите их к `Number`** — используйте
decimal-библиотеку (`decimal.js`, `big.js`, `bignumber.js` и т.п.) для любой арифметики
над суммами.

```ts
import Decimal from 'decimal.js'

const remaining = new Decimal(invoice.amount).minus(invoice.amount_received)
```

## Разработка

```bash
npm install
npm run build       # tsup -> dist/{index.js,index.cjs,index.d.ts}
npm test            # vitest run, сеть не используется — fetch мокается
npm run typecheck   # tsc --noEmit
```
