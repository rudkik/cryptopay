/**
 * TypeScript mirrors of the JSON contract defined in SPEC.md §6.1–6.4.
 * All monetary values are strings in human-readable units (never floats).
 */

export type NetworkCode = 'ethereum' | 'bsc' | 'tron'
export type Currency = 'USDT' | 'USDC'

export type InvoiceStatus =
  | 'pending'
  | 'confirming'
  | 'paid'
  | 'overpaid'
  | 'partially_paid'
  | 'expired'
  | 'cancelled'

export type InvoiceType = 'payment' | 'token_purchase'
export type TransactionStatus = 'detected' | 'confirmed' | 'failed' | 'orphaned'
export type WebhookStatus = 'pending' | 'delivered' | 'failed'
export type TokenPurchaseStatus = 'pending' | 'completed' | 'expired' | 'cancelled'
export type UserRole = 'admin' | 'viewer'

/** SPEC §6.1 — embedded transaction summary inside an Invoice. */
export interface InvoiceTransaction {
  tx_hash: string
  amount: string
  confirmations: number
  confirmations_required: number
  status: TransactionStatus
  explorer_url: string | null
  created_at: string
}

/** SPEC §6.1 — the canonical Invoice object. */
export interface Invoice {
  id: string
  type: InvoiceType
  external_id: string | null
  status: InvoiceStatus
  is_paid: boolean
  /** Null until the payer (or the merchant) picks a currency/network pair. */
  currency: Currency | null
  network: NetworkCode | null
  amount: string
  amount_received: string
  amount_confirmed: string
  address: string | null
  payment_url: string
  qr_payload: string | null
  description: string | null
  customer_email?: string | null
  customer_id?: string | null
  metadata?: Record<string, unknown> | null
  success_url: string | null
  cancel_url: string | null
  expires_at: string | null
  paid_at: string | null
  created_at: string
  /** True while the invoice still needs a currency/network pick before it can be paid. */
  selection_required: boolean
  transactions?: InvoiceTransaction[]
  token_purchase?: TokenPurchase | null
  /** Present on admin detail responses (SPEC §6.4 `GET /invoices/{id}`). */
  webhooks?: WebhookDelivery[]
  /** Present on admin list/detail responses. */
  merchant?: MerchantRef | null
}

/** SPEC §6.3 — public checkout payload: Invoice minus private fields, plus extras. */
export interface PublicInvoice
  extends Omit<Invoice, 'metadata' | 'customer_email' | 'customer_id' | 'merchant' | 'webhooks'> {
  network_name: string | null
  explorer_address_url: string | null
  token_contract: PublicTokenContract | null
  /** 0 while nothing is selected yet. */
  confirmations_required: number
  /** Pairs the payer may pick from; empty once `selection_required` is false. */
  options: InvoiceSelectionOption[]
  merchant_name?: string | null
}

/** One selectable currency/network pair offered on the hosted checkout. */
export interface InvoiceSelectionOption {
  network: NetworkCode
  network_name: string
  chain_id: number | null
  currency: Currency
  confirmations_required: number
  standard: 'ERC-20' | 'BEP-20' | 'TRC-20'
}

export interface PublicTokenContract {
  symbol: Currency
  contract_address: string
  decimals: number
}

export interface MerchantRef {
  id: string
  name: string
  email?: string | null
}

export interface Merchant {
  id: string
  name: string
  email: string | null
  webhook_url: string | null
  is_active: boolean
  settings: MerchantSettings | null
  created_at: string
  updated_at?: string
  /** Aggregates returned by the admin list/detail endpoints. */
  invoices_count?: number
  balances?: Balance[]
  api_keys?: ApiKey[]
}

export interface MerchantSettings {
  underpayment_tolerance?: number | string
  [key: string]: unknown
}

export interface ApiKey {
  id: string
  merchant_id?: string
  name: string
  key_prefix: string
  last_used_at: string | null
  revoked_at: string | null
  created_at: string
}

/** Response of `POST /api/admin/merchants/{id}/api-keys` — key shown once. */
export interface ApiKeyCreated {
  key: string
  api_key: ApiKey
}

export interface WebhookSecretRotated {
  webhook_secret: string
}

export interface TokenContract {
  symbol: Currency
  contract_address: string
  decimals: number
  is_enabled: boolean
  network_code?: NetworkCode
}

export interface Network {
  code: NetworkCode
  name: string
  chain_id: number | null
  confirmations_required: number
  is_enabled: boolean
  explorer_tx_url: string | null
  explorer_address_url: string | null
  last_scanned_block: number | string | null
  watcher_healthy: boolean
  watcher_seen_at: string | null
  token_contracts?: TokenContract[]
  /** Merchant-facing shape (SPEC §6.1 `GET /api/v1/networks`). */
  tokens?: TokenContract[]
}

export interface Transaction {
  id: string
  invoice_id: string | null
  merchant_id?: string | null
  /** `AdminTransactionResource` exposes the DB column `network_code` as `network`. */
  network: NetworkCode
  tx_hash: string
  log_index: number
  from_address: string
  to_address: string
  currency: Currency
  contract_address: string
  amount: string
  amount_raw: string
  block_number: number | string | null
  block_hash?: string | null
  confirmations: number
  confirmations_required?: number
  status: TransactionStatus
  credited_at: string | null
  explorer_url: string | null
  created_at: string
  merchant?: MerchantRef | null
  invoice?: Pick<Invoice, 'id' | 'external_id' | 'status'> | null
}

export interface Balance {
  currency: Currency
  network: NetworkCode
  available: string
  pending: string
  merchant_id?: string
  merchant?: MerchantRef | null
}

export interface BalanceTotals {
  currency: Currency
  available: string
  pending: string
}

export interface Token {
  id: string
  merchant_id: string
  symbol: string
  name: string
  description: string | null
  price_usd: string
  decimals: number
  total_supply: string | null
  sold: string
  min_purchase: string | null
  max_purchase: string | null
  is_active: boolean
  image_url: string | null
  created_at: string
  updated_at?: string
  merchant?: MerchantRef | null
}

export interface TokenPurchase {
  id: string
  invoice_id: string
  token_id: string
  merchant_id: string
  customer_id: string
  customer_email: string | null
  token_amount: string
  price_usd: string
  pay_amount: string
  currency: Currency
  status: TokenPurchaseStatus
  completed_at: string | null
  created_at: string
  token?: Token | null
  merchant?: MerchantRef | null
  invoice?: Pick<Invoice, 'id' | 'status' | 'network' | 'currency'> | null
}

export interface TokenHolding {
  id?: string
  token_id?: string
  merchant_id?: string
  customer_id: string
  amount: string
  token?: Token | null
  updated_at?: string
}

export type WebhookEvent =
  | 'invoice.confirming'
  | 'invoice.paid'
  | 'invoice.overpaid'
  | 'invoice.partially_paid'
  | 'invoice.expired'
  | 'invoice.cancelled'
  | 'token_purchase.completed'

export interface WebhookDelivery {
  id: string
  merchant_id: string
  invoice_id: string | null
  event: WebhookEvent | string
  url: string
  attempts: number
  max_attempts: number
  status: WebhookStatus
  response_code: number | null
  response_body: string | null
  next_attempt_at: string | null
  delivered_at: string | null
  last_error: string | null
  created_at: string
  payload?: Record<string, unknown> | null
  signature?: string | null
  merchant?: MerchantRef | null
}

export interface LedgerEntry {
  id: string
  merchant_id: string
  currency: Currency
  network_code: NetworkCode
  amount: string
  type: 'deposit' | 'adjustment'
  transaction_id: string | null
  balance_after: string
  note: string | null
  created_at: string
}

export interface AdminUser {
  id: number | string
  name: string
  email: string
  role: UserRole
  is_active: boolean
  created_at?: string
}

/** SPEC §6.4 `GET /api/admin/dashboard`. */
export interface DashboardStats {
  invoices_total: number
  invoices_paid: number
  volume_24h: Record<Currency, string>
  volume_total: Record<Currency, string>
  merchants_active: number
  pending_webhooks: number
}

export interface DashboardChartPoint {
  date: string
  USDT: string
  USDC: string
}

export interface DashboardNetwork {
  code: NetworkCode
  name?: string
  is_enabled: boolean
  watcher_healthy: boolean
  last_scanned_block: number | string | null
  watcher_seen_at: string | null
}

export interface DashboardData {
  stats: DashboardStats
  chart: DashboardChartPoint[]
  recent_invoices: Invoice[]
  networks: DashboardNetwork[]
}

/** SPEC §6.6 `GET /health` proxied through `GET /api/admin/watcher/health`. */
export interface WatcherHealth {
  ok: boolean
  networks: WatcherHealthNetwork[]
}

export interface WatcherHealthNetwork {
  code: NetworkCode
  enabled: boolean
  headBlock: number | null
  lastScannedBlock: number | null
  lag: number | null
  pendingTxs: number
  lastError: string | null
  updatedAt: string | null
}

/** Laravel pagination envelope. */
export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from?: number | null
  to?: number | null
}

export interface Paginated<T> {
  data: T[]
  meta: PaginationMeta
  links?: unknown
}

/** SPEC §6 error envelope. */
export interface ApiErrorPayload {
  code: string
  message: string
  details?: Record<string, string[]> | null
}

export interface ApiErrorEnvelope {
  error: ApiErrorPayload
}

export interface LoginResponse {
  token: string
  user: AdminUser
}
