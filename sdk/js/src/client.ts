import { ApiError, CryptoPayError } from './errors.js'
import type {
  BalancesResponse,
  CreateInvoiceParams,
  CreateTokenPurchaseParams,
  Holding,
  Invoice,
  ListInvoicesFilters,
  ListTokenPurchasesFilters,
  ListTransactionsFilters,
  MerchantAccount,
  Network,
  Paginated,
  Token,
  TokenPurchase,
  TokenPurchaseResult,
  Transaction,
} from './types.js'

const DEFAULT_TIMEOUT_MS = 30_000
const USER_AGENT = 'cryptopay-node-sdk/1.0'
/** Потолок на тело ответа. API возвращает небольшой JSON; всё сверх — аномалия. */
const MAX_RESPONSE_BYTES = 8 * 1024 * 1024

export interface CryptoPayOptions {
  /** Секретный API-ключ мерчанта, например `cp_live_...`. */
  apiKey: string
  /** Базовый URL API, например `https://pay.example.com` (с `/api/v1` или без). */
  baseUrl: string
  /** Таймаут запроса в миллисекундах. По умолчанию 30000. */
  timeout?: number
  /** Реализация `fetch` (для тестов/полифилов). По умолчанию `globalThis.fetch`. */
  fetch?: typeof globalThis.fetch
  /** Дополнительные заголовки, отправляемые с каждым запросом. */
  headers?: Record<string, string>
  /** Переопределить заголовок `User-Agent`. */
  userAgent?: string
}

type QueryValue = string | number | boolean | undefined | null

function isLocalHost(hostname: string): boolean {
  const host = hostname.toLowerCase().replace(/^\[|\]$/g, '')
  return (
    host === 'localhost' ||
    host === '::1' ||
    host === 'host.docker.internal' ||
    host.startsWith('127.') ||
    host.endsWith('.localhost') ||
    host.endsWith('.local') ||
    host.endsWith('.test')
  )
}

function normalizeBaseUrl(rawBaseUrl: string): string {
  let url = rawBaseUrl.trim()
  url = url.replace(/\/+$/, '')
  url = url.replace(/\/api\/v1$/, '')

  let parsed: URL
  try {
    parsed = new URL(url)
  } catch {
    throw new TypeError(`CryptoPay: baseUrl is not a valid URL: ${url}`)
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    throw new TypeError(`CryptoPay: baseUrl must be an http:// or https:// URL, got ${parsed.protocol}`)
  }
  // Простой http легитимен для локального стенда (docker-compose поднимает API на
  // http://localhost:8095); где угодно ещё это API-ключ открытым текстом.
  // Предупреждаем, но не падаем — иначе сломаем локальную разработку.
  if (parsed.protocol === 'http:' && !isLocalHost(parsed.hostname)) {
    console.warn(
      'CryptoPay: baseUrl uses plain http against a non-local host — the API key is sent unencrypted. Use https://.',
    )
  }

  return url
}

/**
 * Читает тело ответа с жёстким лимитом, чтобы враждебный или сломанный эндпоинт
 * не смог вычерпать память процесса бесконечным потоком.
 */
async function readBoundedText(response: Response, max = MAX_RESPONSE_BYTES): Promise<string> {
  const declared = Number(response.headers?.get?.('content-length'))
  if (Number.isFinite(declared) && declared > max) {
    throw new CryptoPayError(`CryptoPay: response body exceeds ${max} bytes`)
  }

  const body = response.body as ReadableStream<Uint8Array> | null | undefined
  if (!body || typeof body.getReader !== 'function') {
    const text = await response.text()
    if (Buffer.byteLength(text, 'utf8') > max) {
      throw new CryptoPayError(`CryptoPay: response body exceeds ${max} bytes`)
    }
    return text
  }

  const reader = body.getReader()
  const chunks: Uint8Array[] = []
  let received = 0
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    if (!value) continue
    received += value.byteLength
    if (received > max) {
      await reader.cancel()
      throw new CryptoPayError(`CryptoPay: response body exceeds ${max} bytes`)
    }
    chunks.push(value)
  }
  return Buffer.concat(chunks.map((chunk) => Buffer.from(chunk))).toString('utf8')
}

/** `Retry-After` в секундах, когда сервер прислал простое числовое значение. */
function parseRetryAfter(response: Response): number | null {
  const raw = response.headers?.get?.('retry-after')
  if (typeof raw !== 'string' || !/^\d{1,9}$/.test(raw.trim())) return null
  return Number(raw.trim())
}

function buildQuery(filters?: object): string {
  if (!filters) return ''
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters as Record<string, QueryValue>)) {
    if (value === undefined || value === null) continue
    params.set(key, String(value))
  }
  const qs = params.toString()
  return qs ? `?${qs}` : ''
}

function encodePathSegment(segment: string): string {
  return encodeURIComponent(segment)
}

/** Клиент CryptoPay Merchant API. */
export class CryptoPay {
  private readonly apiKey: string
  private readonly baseUrl: string
  private readonly timeout: number
  private readonly fetchImpl: typeof globalThis.fetch
  private readonly extraHeaders: Record<string, string>
  private readonly userAgent: string

  constructor(options: CryptoPayOptions)
  constructor(apiKey: string, baseUrl: string, options?: Omit<CryptoPayOptions, 'apiKey' | 'baseUrl'>)
  constructor(
    apiKeyOrOptions: string | CryptoPayOptions,
    baseUrl?: string,
    positionalOptions?: Omit<CryptoPayOptions, 'apiKey' | 'baseUrl'>,
  ) {
    let options: CryptoPayOptions
    if (typeof apiKeyOrOptions === 'string') {
      if (typeof baseUrl !== 'string' || baseUrl.length === 0) {
        throw new TypeError('CryptoPay: baseUrl is required')
      }
      options = { apiKey: apiKeyOrOptions, baseUrl, ...positionalOptions }
    } else {
      options = apiKeyOrOptions
    }

    if (!options.apiKey || typeof options.apiKey !== 'string') {
      throw new TypeError('CryptoPay: apiKey is required')
    }
    if (!options.baseUrl || typeof options.baseUrl !== 'string') {
      throw new TypeError('CryptoPay: baseUrl is required')
    }

    this.apiKey = options.apiKey
    this.baseUrl = normalizeBaseUrl(options.baseUrl)
    this.timeout = options.timeout ?? DEFAULT_TIMEOUT_MS
    this.fetchImpl = options.fetch ?? globalThis.fetch
    this.extraHeaders = options.headers ?? {}
    this.userAgent = options.userAgent ?? USER_AGENT

    if (typeof this.fetchImpl !== 'function') {
      throw new TypeError(
        'CryptoPay: global fetch is not available; pass options.fetch (Node < 18, or a custom runtime)',
      )
    }
  }

  private async request<T>(
    method: 'GET' | 'POST',
    path: string,
    options?: { query?: object; body?: unknown; idempotencyKey?: string },
  ): Promise<T> {
    const query = buildQuery(options?.query)
    const url = `${this.baseUrl}/api/v1${path}${query}`

    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.apiKey}`,
      Accept: 'application/json',
      'User-Agent': this.userAgent,
      ...this.extraHeaders,
    }

    const hasBody = options?.body !== undefined
    if (hasBody) {
      headers['Content-Type'] = 'application/json'
    }
    if (options?.idempotencyKey) {
      headers['Idempotency-Key'] = options.idempotencyKey
    }

    let response: Response
    try {
      response = await this.fetchImpl(url, {
        method,
        headers,
        body: hasBody ? JSON.stringify(options?.body) : undefined,
        signal: AbortSignal.timeout(this.timeout),
        // Merchant API никогда не редиректит. Следовать 3xx означало бы унести
        // заголовок Authorization на адрес, который выбрали не мы.
        redirect: 'manual',
      })
    } catch (err) {
      if (err instanceof Error && err.name === 'TimeoutError') {
        throw new CryptoPayError(`CryptoPay: request timed out after ${this.timeout}ms`, { cause: err })
      }
      if (err instanceof Error && err.name === 'AbortError') {
        throw new CryptoPayError(`CryptoPay: request timed out after ${this.timeout}ms`, { cause: err })
      }
      throw new CryptoPayError(`CryptoPay: network request failed: ${(err as Error)?.message ?? err}`, {
        cause: err,
      })
    }

    if (response.status >= 300 && response.status < 400) {
      throw new CryptoPayError(
        `CryptoPay: unexpected redirect (HTTP ${response.status}) from ${this.baseUrl} — check baseUrl`,
      )
    }

    if (!response.ok) {
      await this.throwApiError(response)
    }

    if (response.status === 204) {
      return undefined as T
    }

    const text = await readBoundedText(response)
    if (!text) {
      return undefined as T
    }
    return JSON.parse(text) as T
  }

  private async throwApiError(response: Response): Promise<never> {
    const raw = await readBoundedText(response)
    const retryAfter = parseRetryAfter(response)
    let parsed: unknown
    try {
      parsed = raw ? JSON.parse(raw) : undefined
    } catch {
      parsed = undefined
    }

    if (
      parsed &&
      typeof parsed === 'object' &&
      'error' in parsed &&
      parsed.error &&
      typeof parsed.error === 'object'
    ) {
      const err = parsed.error as { code?: unknown; message?: unknown; details?: unknown }
      throw new ApiError(typeof err.message === 'string' ? err.message : `HTTP ${response.status}`, {
        code: typeof err.code === 'string' ? err.code : 'http_error',
        details: (err.details && typeof err.details === 'object' ? err.details : {}) as Record<string, unknown>,
        status: response.status,
        retryAfter,
      })
    }

    throw new ApiError(`HTTP ${response.status}`, {
      code: response.status >= 500 ? 'server_error' : 'http_error',
      details: { body: raw.slice(0, 2048) },
      status: response.status,
      retryAfter,
    })
  }

  /** Создать счёт (invoice). */
  async createInvoice(params: CreateInvoiceParams, idempotencyKey?: string): Promise<Invoice> {
    const { data } = await this.request<{ data: Invoice }>('POST', '/invoices', {
      body: params,
      idempotencyKey,
    })
    return data
  }

  /** Получить счёт по id. */
  async getInvoice(id: string): Promise<Invoice> {
    const { data } = await this.request<{ data: Invoice }>('GET', `/invoices/${encodePathSegment(id)}`)
    return data
  }

  /** Постраничный список счетов. */
  async listInvoices(filters?: ListInvoicesFilters): Promise<Paginated<Invoice>> {
    return this.request<Paginated<Invoice>>('GET', '/invoices', { query: filters })
  }

  /** Отменить счёт. */
  async cancelInvoice(id: string): Promise<Invoice> {
    const { data } = await this.request<{ data: Invoice }>('POST', `/invoices/${encodePathSegment(id)}/cancel`)
    return data
  }

  /** Список поддерживаемых сетей. */
  async networks(): Promise<Network[]> {
    const { data } = await this.request<{ data: Network[] }>('GET', '/networks')
    return data
  }

  /** Балансы мерчанта по сетям/валютам, с агрегированными итогами. */
  async balances(): Promise<BalancesResponse> {
    return this.request<BalancesResponse>('GET', '/balances')
  }

  /** Постраничный список транзакций. */
  async transactions(filters?: ListTransactionsFilters): Promise<Paginated<Transaction>> {
    return this.request<Paginated<Transaction>>('GET', '/transactions', { query: filters })
  }

  /** Список токенов (token sale). */
  async tokens(): Promise<Token[]> {
    const { data } = await this.request<{ data: Token[] }>('GET', '/tokens')
    return data
  }

  /** Получить токен по id. */
  async getToken(id: string): Promise<Token> {
    const { data } = await this.request<{ data: Token }>('GET', `/tokens/${encodePathSegment(id)}`)
    return data
  }

  /**
   * Создать покупку токенов. В отличие от остальных методов, ответ API НЕ обёрнут в `data` —
   * это `{purchase, invoice}` напрямую.
   */
  async createTokenPurchase(
    params: CreateTokenPurchaseParams,
    idempotencyKey?: string,
  ): Promise<TokenPurchaseResult> {
    return this.request<TokenPurchaseResult>('POST', '/token-purchases', {
      body: params,
      idempotencyKey,
    })
  }

  /** Получить покупку токенов по id. */
  async getTokenPurchase(id: string): Promise<TokenPurchase> {
    const { data } = await this.request<{ data: TokenPurchase }>(
      'GET',
      `/token-purchases/${encodePathSegment(id)}`,
    )
    return data
  }

  /** Постраничный список покупок токенов. */
  async listTokenPurchases(filters?: ListTokenPurchasesFilters): Promise<Paginated<TokenPurchase>> {
    return this.request<Paginated<TokenPurchase>>('GET', '/token-purchases', { query: filters })
  }

  /** Список токен-холдингов покупателя (не пагинируется). */
  async customerHoldings(customerId: string): Promise<Holding[]> {
    const { data } = await this.request<{ data: Holding[] }>(
      'GET',
      `/customers/${encodePathSegment(customerId)}/holdings`,
    )
    return data
  }

  /** Данные текущего мерчанта (аккаунт, балансы, вебхук, API-ключ). */
  async me(): Promise<MerchantAccount> {
    const { data } = await this.request<{ data: MerchantAccount }>('GET', '/me')
    return data
  }
}
