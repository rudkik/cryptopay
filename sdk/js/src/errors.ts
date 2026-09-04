/**
 * Базовый класс ошибок SDK. Все ошибки, выбрасываемые SDK, наследуются от него,
 * так что `catch (err) { if (err instanceof CryptoPayError) ... }` ловит их все.
 */
export class CryptoPayError extends Error {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options)
    this.name = 'CryptoPayError'
    Object.setPrototypeOf(this, CryptoPayError.prototype)
  }
}

/** Структура `details` внутри {@link ApiError} — сырые данные из поля `error.details` ответа API. */
export type ApiErrorDetails = Record<string, unknown>

/**
 * Ошибка, возвращённая CryptoPay API (HTTP-статус >= 400).
 *
 * Envelope: `{"error":{"code":"...","message":"...","details":{...}}}`.
 */
export class ApiError extends CryptoPayError {
  readonly code: string
  readonly details: ApiErrorDetails
  readonly status: number
  /**
   * Секунды до повторной попытки из заголовка `Retry-After` (429/503), либо `null`.
   *
   * SDK никогда не повторяет запрос сам — бэкофф остаётся решением вызывающего кода,
   * поэтому здесь нет скрытого цикла ретраев, способного добить лимитированный API.
   */
  readonly retryAfter: number | null

  constructor(
    message: string,
    options: {
      code: string
      details?: ApiErrorDetails
      status: number
      cause?: unknown
      retryAfter?: number | null
    },
  ) {
    super(message, options.cause !== undefined ? { cause: options.cause } : undefined)
    this.name = 'ApiError'
    this.code = options.code
    this.details = options.details ?? {}
    this.status = options.status
    this.retryAfter = options.retryAfter ?? null
    Object.setPrototypeOf(this, ApiError.prototype)
  }

  /** `true`, если ошибка валидации (HTTP 422, code `validation_error`). */
  get isValidationError(): boolean {
    return this.code === 'validation_error'
  }

  /** `true`, если ресурс не найден (HTTP 404, code `not_found`). */
  get isNotFound(): boolean {
    return this.code === 'not_found'
  }

  /** `true`, если превышен лимит запросов (HTTP 429, code `rate_limited`). */
  get isRateLimited(): boolean {
    return this.code === 'rate_limited'
  }

  /** `true`, если путь есть, но не для этого HTTP-метода (HTTP 405, code `method_not_allowed`). */
  get isMethodNotAllowed(): boolean {
    return this.code === 'method_not_allowed'
  }
}

/** Ошибка проверки подписи вебхука (см. {@link verifyWebhook}). */
export class SignatureError extends CryptoPayError {
  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options)
    this.name = 'SignatureError'
    Object.setPrototypeOf(this, SignatureError.prototype)
  }
}
