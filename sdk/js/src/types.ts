/**
 * Типы для CryptoPay Merchant API.
 *
 * ВАЖНО: все денежные суммы в API передаются СТРОКАМИ (decimal strings), а не числами.
 * Не приводите их к `number` — используйте decimal-библиотеку (например `decimal.js`
 * или `big.js`) для арифметики, чтобы не терять точность.
 */

/** Статус счёта (invoice). */
export type InvoiceStatus =
  | 'pending'
  | 'confirming'
  | 'paid'
  | 'overpaid'
  | 'partially_paid'
  | 'expired'
  | 'cancelled'

/** Тип счёта. */
export type InvoiceType = 'payment' | 'token_purchase'

/**
 * Валюта. Строгий список известных значений с "open" fallback —
 * неизвестные будущие значения не сломают типизацию потребителя SDK.
 */
export type Currency = 'USDT' | 'USDC' | (string & {})

/** Код сети. Тоже "open union" — см. {@link Currency}. */
export type NetworkCode = 'ethereum' | 'bsc' | 'tron' | (string & {})

/** Строгий список валют для тела запроса (без open fallback). */
export type CurrencyParam = 'USDT' | 'USDC'

/** Строгий список сетей для тела запроса (без open fallback). */
export type NetworkCodeParam = 'ethereum' | 'bsc' | 'tron'

export type TransactionStatus = 'detected' | 'confirmed' | 'failed' | 'orphaned'

export type TokenPurchaseStatus = 'pending' | 'completed' | 'expired' | 'cancelled'

export interface TokenInfo {
  symbol: string
  contract_address: string
  decimals: number
}

export interface Network {
  code: NetworkCode
  name: string
  chain_id: number | null
  confirmations_required: number
  tokens: TokenInfo[]
}

export interface Invoice {
  id: string
  type: InvoiceType
  external_id: string | null
  status: InvoiceStatus
  is_paid: boolean
  /** `null`, пока валюта не выбрана — см. {@link Invoice.selection_required}. */
  currency: Currency | null
  /** `null`, пока сеть не выбрана — см. {@link Invoice.selection_required}. */
  network: NetworkCode | null
  /**
   * Счёт создан без пары currency/network — её выбирает плательщик на hosted-странице
   * оплаты (или мерчант через `selectInvoiceNetwork()`). Пока `true`, поля `currency`,
   * `network`, `address` и `qr_payload` равны `null`: адрес выпускается только после выбора.
   */
  selection_required: boolean
  /** Decimal string. */
  amount: string
  /** Decimal string. */
  amount_received: string
  /** Decimal string. */
  amount_confirmed: string
  address: string | null
  payment_url: string
  qr_payload: string | null
  description: string | null
  customer_email: string | null
  customer_id: string | null
  metadata: Record<string, unknown> | null
  success_url: string | null
  cancel_url: string | null
  expires_at: string
  paid_at: string | null
  created_at: string
  transactions: Transaction[]
  token_purchase: TokenPurchase | null
}

export interface Transaction {
  id: string
  invoice_id: string | null
  network: NetworkCode
  tx_hash: string
  log_index: number
  from_address: string | null
  to_address: string
  currency: Currency
  contract_address: string | null
  /** Decimal string. */
  amount: string
  /** Raw on-chain integer amount, as a string (respects token decimals). */
  amount_raw: string | null
  block_number: number | null
  confirmations: number
  confirmations_required: number
  status: TransactionStatus
  /** `null`, если для сети не настроен шаблон обозревателя. */
  explorer_url: string | null
  credited_at: string | null
  created_at: string
}

export interface Balance {
  currency: Currency
  network: NetworkCode
  /** Decimal string. */
  available: string
  /** Decimal string. */
  pending: string
}

export interface BalancesResponse {
  data: Balance[]
  totals: Record<string, { available: string; pending: string }>
}

export interface Token {
  id: string
  merchant_id: string
  symbol: string
  name: string
  description: string | null
  /** Decimal string. */
  price_usd: string
  decimals: number
  /** Decimal string, or null if unlimited. */
  total_supply: string | null
  /** Decimal string. */
  sold: string
  /** Decimal string. */
  min_purchase: string
  /** Decimal string, or null if unlimited. */
  max_purchase: string | null
  is_active: boolean
  image_url: string | null
  created_at: string
}

export interface TokenPurchase {
  id: string
  invoice_id: string
  token_id: string
  merchant_id: string
  customer_id: string
  customer_email: string | null
  /** Decimal string. */
  token_amount: string
  /** Decimal string. */
  price_usd: string
  /** Decimal string. */
  pay_amount: string
  /** `null`, пока пара currency/network не выбрана — см. {@link Invoice.selection_required}. */
  currency: Currency | null
  status: TokenPurchaseStatus
  completed_at: string | null
  created_at: string
  token: Token | null
}

/** Ответ POST /token-purchases — БЕЗ обёртки `data` (в отличие от остальных эндпоинтов). */
export interface TokenPurchaseResult {
  purchase: TokenPurchase
  invoice: Invoice
}

export interface Holding {
  token: Token | null
  customer_id: string
  /** Decimal string. */
  amount: string
  updated_at: string
}

export interface ApiKeyInfo {
  id: string
  merchant_id: string
  name: string
  key_prefix: string
  last_used_at: string | null
  revoked_at: string | null
  created_at: string
}

export interface MerchantWebhookInfo {
  url: string | null
  configured: boolean
  events: string[]
  signature_header: string
}

export interface MerchantAccount {
  id: string
  name: string
  email: string
  webhook_url: string | null
  is_active: boolean
  settings: Record<string, unknown>
  /** Decimal string. */
  underpayment_tolerance: string
  created_at: string
  balances: Balance[]
  webhook: MerchantWebhookInfo
  api_key: ApiKeyInfo | null
}

export interface PaginationLink {
  url: string | null
  label: string
  /** Отсутствует у разделителя «...» в середине длинной пагинации. */
  page?: number | null
  active: boolean
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  links: PaginationLink[]
  path: string
  per_page: number
  to: number | null
  total: number
}

export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface Paginated<T> {
  data: T[]
  meta: PaginationMeta
  links: PaginationLinks
}

/* ------------------------------------------------------------------ */
/* Параметры запросов                                                  */
/* ------------------------------------------------------------------ */

export interface CreateInvoiceParams {
  /** Decimal string, > 0. Сумма номинирована в USD и от сети не зависит (USDT = USDC = 1 USD). */
  amount: string
  /**
   * Передаются либо ОБА поля (`currency` + `network`), либо НИ ОДНОГО: ровно одно из двух —
   * `422 validation_error`. Без пары счёт создаётся с `selection_required: true`, и валюту
   * с сетью выбирает плательщик на hosted-странице оплаты.
   */
  currency?: CurrencyParam
  /** Правило «оба или ни одного» — см. {@link CreateInvoiceParams.currency}. */
  network?: NetworkCodeParam
  external_id?: string
  description?: string
  customer_email?: string
  customer_id?: string
  metadata?: Record<string, unknown>
  success_url?: string
  cancel_url?: string
  /** Секунды, по умолчанию 3600, максимум 86400. */
  expires_in?: number
}

/** Тело `POST /invoices/{id}/select` — выбор валюты и сети для счёта, созданного без них. */
export interface SelectInvoiceNetworkParams {
  currency: CurrencyParam
  network: NetworkCodeParam
}

export interface ListInvoicesFilters {
  status?: InvoiceStatus
  external_id?: string
  network?: NetworkCodeParam
  currency?: CurrencyParam
  from?: string
  to?: string
  /** <= 100 */
  per_page?: number
  page?: number
}

export interface ListTransactionsFilters {
  invoice_id?: string
  network?: NetworkCodeParam
  status?: TransactionStatus
  per_page?: number
  page?: number
}

export interface CreateTokenPurchaseParamsBase {
  token_id: string
  /**
   * Как и в {@link CreateInvoiceParams}: либо ОБА поля (`currency` + `network`), либо НИ ОДНОГО.
   * Без пары счёт покупки создаётся с `selection_required: true`.
   */
  currency?: CurrencyParam
  /** Правило «оба или ни одного» — см. {@link CreateTokenPurchaseParamsBase.currency}. */
  network?: NetworkCodeParam
  customer_id: string
  customer_email?: string
  external_id?: string
  success_url?: string
  cancel_url?: string
  metadata?: Record<string, unknown>
  expires_in?: number
}

export type CreateTokenPurchaseParams =
  | (CreateTokenPurchaseParamsBase & { token_amount: string; pay_amount?: never })
  | (CreateTokenPurchaseParamsBase & { pay_amount: string; token_amount?: never })

export interface ListTokenPurchasesFilters {
  customer_id?: string
  status?: TokenPurchaseStatus
  token_id?: string
  per_page?: number
  page?: number
}

/* ------------------------------------------------------------------ */
/* Webhooks                                                            */
/* ------------------------------------------------------------------ */

export type WebhookEventName =
  | 'invoice.confirming'
  | 'invoice.paid'
  | 'invoice.overpaid'
  | 'invoice.partially_paid'
  | 'invoice.expired'
  | 'invoice.cancelled'
  | 'token_purchase.completed'

export interface WebhookPayload {
  id: string
  event: WebhookEventName
  created_at: string
  data: {
    invoice: Invoice | null
    token_purchase: TokenPurchase | null
  }
}

export interface WebhookEvent {
  id: string
  event: WebhookEventName
  created_at: string
  deliveryId: string
  invoice: Invoice | null
  tokenPurchase: TokenPurchase | null
  payload: WebhookPayload
  isPaid: boolean
}
