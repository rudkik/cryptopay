import { createHmac, timingSafeEqual } from 'node:crypto'
import { SignatureError } from './errors.js'
import type { WebhookEvent, WebhookPayload } from './types.js'

const SIGNATURE_HEADER = 'x-cryptopay-signature'
const TIMESTAMP_HEADER = 'x-cryptopay-timestamp'
const DELIVERY_HEADER = 'x-cryptopay-delivery'

/** Единственная форма подписи, которую отдаёт бэкенд: `sha256=` + 64 hex-символа. */
const SIGNATURE_PATTERN = /^sha256=[0-9a-f]{64}$/
/** Unix-таймстамп — беззнаковое десятичное целое, и ничего кроме. */
const TIMESTAMP_PATTERN = /^[0-9]{1,19}$/

export interface VerifyWebhookOptions {
  /** Допустимое расхождение таймстампа в секундах. По умолчанию 300. `<= 0` отключает проверку. */
  tolerance?: number
}

export interface CryptoPayWebhookOptions {
  /** Допустимое расхождение таймстампа в секундах (см. {@link VerifyWebhookOptions}). */
  tolerance?: number
  /** Кастомная обработка ошибки подписи вместо ответа 400 по умолчанию. */
  onError?: (err: SignatureError, req: unknown, res: unknown) => void
}

function toBuffer(input: string | Buffer | Uint8Array): Buffer {
  if (Buffer.isBuffer(input)) return input
  if (input instanceof Uint8Array) return Buffer.from(input)
  return Buffer.from(input, 'utf8')
}

/** Регистронезависимый поиск заголовка; значение-массив берётся как `[0]`. */
function getHeader(
  headers: Record<string, string | string[] | undefined>,
  name: string,
): string | undefined {
  const lower = name.toLowerCase()
  for (const key of Object.keys(headers)) {
    if (key.toLowerCase() === lower) {
      const value = headers[key]
      return Array.isArray(value) ? value[0] : value
    }
  }
  return undefined
}

/** Постоянно-временное сравнение строк (без утечки по длине через ранний return значения). */
function safeCompare(a: string, b: string): boolean {
  const aBuf = Buffer.from(a, 'utf8')
  const bBuf = Buffer.from(b, 'utf8')
  if (aBuf.length !== bBuf.length) {
    // Всё равно выполняем сравнение той же длины, чтобы не давать заметный по времени "быстрый выход".
    timingSafeEqual(bBuf, bBuf)
    return false
  }
  return timingSafeEqual(aBuf, bBuf)
}

/**
 * Подписывает тело вебхука так же, как это делает бэкенд CryptoPay (`WebhookService::sign()`):
 * `'sha256=' + hex(hmac_sha256(secret, timestamp + "." + rawBody))`.
 *
 * Принимает `Buffer`/строку "как есть" — не проводит тело через lossy-кодирование.
 */
export function signWebhook(secret: string, timestamp: number | string, rawBody: string | Buffer): string {
  const prefix = Buffer.from(`${timestamp}.`, 'utf8')
  const bodyBuf = toBuffer(rawBody)
  const signedPayload = Buffer.concat([prefix, bodyBuf])
  const hex = createHmac('sha256', secret).update(signedPayload).digest('hex')
  return `sha256=${hex}`
}

/**
 * Проверяет подпись и таймстамп входящего вебхука CryptoPay и парсит его в удобный объект события.
 *
 * @throws {SignatureError} при отсутствующей подписи/таймстампе, "протухшем" таймстампе,
 *   несовпадении подписи, либо если тело не является валидным JSON.
 */
export function verifyWebhook(
  rawBody: string | Buffer | Uint8Array,
  headers: Record<string, string | string[] | undefined>,
  secret: string,
  options?: VerifyWebhookOptions,
): WebhookEvent {
  const tolerance = options?.tolerance ?? 300

  // Пустой секрет — это не «нет проверки», а «проверку пройдёт кто угодно»:
  // HMAC с пустым ключом вычисляется тривиально. Отсутствующий
  // CRYPTOPAY_WEBHOOK_SECRET обязан приводить к отказу, а не к приёму.
  if (typeof secret !== 'string' || secret.length === 0) {
    throw new SignatureError('CryptoPay webhook: secret is not configured')
  }

  const signatureHeader = getHeader(headers, SIGNATURE_HEADER)
  if (!signatureHeader) {
    throw new SignatureError('CryptoPay webhook: missing X-CryptoPay-Signature header')
  }

  // Hex на проводе регистронезависим, а сравнение ниже — нет.
  const signature = signatureHeader.toLowerCase()
  if (!SIGNATURE_PATTERN.test(signature)) {
    throw new SignatureError('CryptoPay webhook: malformed X-CryptoPay-Signature header')
  }

  const timestampHeader = getHeader(headers, TIMESTAMP_HEADER)
  if (!timestampHeader) {
    throw new SignatureError('CryptoPay webhook: missing X-CryptoPay-Timestamp header')
  }
  if (!TIMESTAMP_PATTERN.test(timestampHeader)) {
    throw new SignatureError('CryptoPay webhook: X-CryptoPay-Timestamp header is not a valid number')
  }
  const timestamp = Number(timestampHeader)

  if (tolerance > 0) {
    const now = Math.floor(Date.now() / 1000)
    // Math.abs закрывает окно в ОБЕ стороны: и повтор протухшей доставки,
    // и таймстамп из будущего отвергаются одинаково.
    if (Math.abs(now - timestamp) > tolerance) {
      throw new SignatureError(
        `CryptoPay webhook: timestamp is outside of the allowed tolerance (${tolerance}s)`,
      )
    }
  }

  const bodyBuf = toBuffer(rawBody)
  const expectedSignature = signWebhook(secret, timestampHeader, bodyBuf)
  if (!safeCompare(signature, expectedSignature)) {
    throw new SignatureError('CryptoPay webhook: signature mismatch')
  }

  // Только теперь телу можно доверять настолько, чтобы его разобрать.

  let payload: WebhookPayload
  try {
    payload = JSON.parse(bodyBuf.toString('utf8')) as WebhookPayload
  } catch (err) {
    throw new SignatureError('CryptoPay webhook: request body is not valid JSON', { cause: err })
  }

  const deliveryHeader = getHeader(headers, DELIVERY_HEADER)
  const deliveryId = deliveryHeader ?? payload.id
  const invoice = payload.data?.invoice ?? null
  const tokenPurchase = payload.data?.token_purchase ?? null
  const reversal = payload.data?.reversal ?? null

  return {
    id: payload.id,
    event: payload.event,
    created_at: payload.created_at,
    deliveryId,
    invoice,
    tokenPurchase,
    reversal,
    payload,
    isPaid: payload.event === 'invoice.paid' || payload.event === 'invoice.overpaid',
    isReversed: payload.event === 'invoice.reversed',
  }
}

/**
 * Express-совместимый middleware для обработки вебхуков CryptoPay.
 *
 * Требует "сырое" тело запроса — смонтируйте `express.raw({ type: 'application/json' })`
 * на роуте ДО этого middleware, либо предоставьте `req.rawBody` самостоятельно.
 */
export function cryptoPayWebhook(
  secret: string,
  handler: (event: WebhookEvent, req: any, res: any) => void | Promise<void>,
  options?: CryptoPayWebhookOptions,
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
): (req: any, res: any, next: any) => void {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  return (req: any, res: any, next: any): void => {
    void (async () => {
      let rawBody: string | Buffer | undefined
      if (Buffer.isBuffer(req.body) || typeof req.body === 'string') {
        rawBody = req.body
      } else if (req.rawBody !== undefined) {
        rawBody = req.rawBody
      }

      if (rawBody === undefined) {
        res.status(400).json({
          error: {
            code: 'invalid_request',
            message:
              'CryptoPay webhook: raw request body is not available. Mount express.raw({ type: "application/json" }) ' +
              'on this route before cryptoPayWebhook(), or set req.rawBody yourself.',
            details: {},
          },
        })
        return
      }

      let event: WebhookEvent
      try {
        event = verifyWebhook(rawBody, req.headers ?? {}, secret, { tolerance: options?.tolerance })
      } catch (err) {
        if (err instanceof SignatureError) {
          if (options?.onError) {
            options.onError(err, req, res)
          } else {
            // Конкретная причина (нет заголовка / протухший таймстамп / несовпадение
            // подписи) полезна оператору и ровно так же полезна тому, кто перебирает
            // подписи. Наружу — общий текст, детали остаются в `err` для onError.
            res.status(400).json({
              error: { code: 'invalid_signature', message: 'Invalid webhook signature.', details: {} },
            })
          }
          return
        }
        throw err
      }

      try {
        await handler(event, req, res)
        if (!res.headersSent) {
          res.status(200).json({ received: true })
        }
      } catch (err) {
        if (!res.headersSent) {
          res.status(500).json({
            error: { code: 'server_error', message: 'CryptoPay webhook handler failed', details: {} },
          })
        }
        next(err)
      }
    })()
  }
}
