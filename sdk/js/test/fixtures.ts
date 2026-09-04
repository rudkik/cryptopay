import type { Balance, Invoice, Network, Token, TokenPurchase, Transaction } from '../src/types.js'

/** Живой пример из CONTRACT.md (verified against live API 2026-09-02). */
export const invoiceFixture: Invoice = {
  id: '01a06089-c3c9-7394-9750-480c130315fd',
  type: 'payment',
  external_id: 'probe-1',
  status: 'pending',
  is_paid: false,
  currency: 'USDT',
  network: 'tron',
  selection_required: false,
  amount: '12.500000',
  amount_received: '0.000000',
  amount_confirmed: '0.000000',
  address: 'TAofMk3example',
  payment_url: 'http://localhost:8095/pay/01a06089-c3c9-7394-9750-480c130315fd',
  qr_payload: 'TAofMk3example',
  description: null,
  customer_email: null,
  customer_id: null,
  metadata: { a: 1 },
  success_url: null,
  cancel_url: null,
  expires_at: '2026-09-02T06:13:56+00:00',
  paid_at: null,
  created_at: '2026-09-02T05:13:56+00:00',
  transactions: [],
  token_purchase: null,
}

export function invoicePayload(overrides: Partial<Invoice> = {}): unknown {
  return { data: { ...invoiceFixture, ...overrides } }
}

/** Счёт, созданный без currency/network — плательщик выбирает пару сам. */
export const unselectedInvoiceFixture: Invoice = {
  ...invoiceFixture,
  currency: null,
  network: null,
  address: null,
  qr_payload: null,
  selection_required: true,
}

export const transactionFixture: Transaction = {
  id: 'a1b2c3d4-0000-0000-0000-000000000000',
  invoice_id: null,
  network: 'bsc',
  tx_hash: '0xabc123',
  log_index: 0,
  from_address: '0xfrom',
  to_address: '0xto',
  currency: 'USDC',
  contract_address: '0xcontract',
  amount: '100.000000000000000000',
  amount_raw: '100000000000000000000',
  block_number: 119430112,
  confirmations: 15,
  confirmations_required: 15,
  status: 'confirmed',
  explorer_url: 'https://bscscan.com/tx/0xabc123',
  credited_at: null,
  created_at: '2026-09-02T05:13:56+00:00',
}

export const networkFixture: Network = {
  code: 'ethereum',
  name: 'Ethereum',
  chain_id: 1,
  confirmations_required: 12,
  tokens: [{ symbol: 'USDT', contract_address: '0xdAC17F958D2ee523a2206206994597C13D831ec7', decimals: 6 }],
}

export const balanceFixture: Balance = {
  currency: 'USDT',
  network: 'tron',
  available: '100.000000',
  pending: '0.000000',
}

export const tokenFixture: Token = {
  id: 'tok_1',
  merchant_id: 'm_1',
  symbol: 'PLT',
  name: 'Platform Token',
  description: null,
  price_usd: '0.50',
  decimals: 18,
  total_supply: '1000000',
  sold: '100',
  min_purchase: '1',
  max_purchase: null,
  is_active: true,
  image_url: null,
  created_at: '2026-09-02T05:13:56+00:00',
}

export const tokenPurchaseFixture: TokenPurchase = {
  id: 'tp_1',
  invoice_id: invoiceFixture.id,
  token_id: tokenFixture.id,
  merchant_id: 'm_1',
  customer_id: 'cust_1',
  customer_email: null,
  token_amount: '200',
  price_usd: '0.50',
  pay_amount: '100.000000',
  currency: 'USDT',
  status: 'pending',
  completed_at: null,
  created_at: '2026-09-02T05:13:56+00:00',
  token: tokenFixture,
}

export function paginatedPayload<T>(items: T[], overrides: Partial<{ current_page: number; last_page: number; total: number; per_page: number }> = {}): unknown {
  const perPage = overrides.per_page ?? 15
  const currentPage = overrides.current_page ?? 1
  const lastPage = overrides.last_page ?? 1
  const total = overrides.total ?? items.length
  return {
    data: items,
    links: { first: 'http://localhost:8095/api/v1/invoices?page=1', last: null, prev: null, next: null },
    meta: {
      current_page: currentPage,
      from: items.length ? 1 : null,
      last_page: lastPage,
      links: [],
      path: 'http://localhost:8095/api/v1/invoices',
      per_page: perPage,
      to: items.length,
      total,
    },
  }
}

export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

export function errorResponse(code: string, message: string, details: Record<string, unknown> = {}, status = 400): Response {
  return jsonResponse({ error: { code, message, details } }, status)
}

export function textResponse(body: string, status = 500): Response {
  return new Response(body, { status, headers: { 'Content-Type': 'text/html' } })
}
