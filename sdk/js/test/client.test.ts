import { describe, expect, it, vi } from 'vitest'
import { ApiError, CryptoPay } from '../src/index.js'
import type { CryptoPayOptions } from '../src/index.js'
import {
  balanceFixture,
  errorResponse,
  invoiceFixture,
  invoicePayload,
  jsonResponse,
  networkFixture,
  paginatedPayload,
  textResponse,
  tokenFixture,
  tokenPurchaseFixture,
} from './fixtures.js'

type FetchCall = { url: string; init: RequestInit }

function fakeFetch(handler: (url: string, init: RequestInit) => Response | Promise<Response>) {
  const calls: FetchCall[] = []
  const fn = vi.fn(async (input: Parameters<typeof globalThis.fetch>[0], init?: RequestInit) => {
    const url = typeof input === 'string' ? input : input.toString()
    calls.push({ url, init: init ?? {} })
    return handler(url, init ?? {})
  }) as unknown as typeof globalThis.fetch
  return { fn, calls }
}

function client(handler: (url: string, init: RequestInit) => Response | Promise<Response>, extra: Partial<Omit<CryptoPayOptions, 'apiKey' | 'baseUrl' | 'fetch'>> = {}) {
  const { fn, calls } = fakeFetch(handler)
  const c = new CryptoPay({
    apiKey: 'cp_live_test',
    baseUrl: 'http://localhost:8095',
    fetch: fn,
    ...extra,
  })
  return { c, calls }
}

describe('CryptoPay client — construction', () => {
  it('throws TypeError when apiKey is missing', () => {
    // @ts-expect-error deliberately omitting apiKey
    expect(() => new CryptoPay({ baseUrl: 'http://x' })).toThrow(TypeError)
  })

  it('throws TypeError when baseUrl is missing', () => {
    // @ts-expect-error deliberately omitting baseUrl
    expect(() => new CryptoPay({ apiKey: 'cp_live_x' })).toThrow(TypeError)
  })

  it('supports the positional constructor form', () => {
    const { fn } = fakeFetch(() => jsonResponse(invoicePayload()))
    const c = new CryptoPay('cp_live_test', 'http://localhost:8095', { fetch: fn })
    expect(c).toBeInstanceOf(CryptoPay)
  })
})

describe('createInvoice', () => {
  it('POSTs to the exact URL with correct headers and JSON body', async () => {
    const { c, calls } = client(() => jsonResponse(invoicePayload(), 201))

    const invoice = await c.createInvoice(
      { amount: '12.5', currency: 'USDT', network: 'tron', external_id: 'probe-1' },
      'idem-key-1',
    )

    expect(calls).toHaveLength(1)
    expect(calls[0]!.url).toBe('http://localhost:8095/api/v1/invoices')
    expect(calls[0]!.init.method).toBe('POST')
    const headers = calls[0]!.init.headers as Record<string, string>
    expect(headers.Authorization).toBe('Bearer cp_live_test')
    expect(headers['Content-Type']).toBe('application/json')
    expect(headers['Idempotency-Key']).toBe('idem-key-1')
    expect(JSON.parse(calls[0]!.init.body as string)).toEqual({
      amount: '12.5',
      currency: 'USDT',
      network: 'tron',
      external_id: 'probe-1',
    })

    expect(invoice).toEqual(invoiceFixture)
    expect(invoice.amount).toBe('12.500000')
    expect(typeof invoice.amount).toBe('string')
  })

  it('omits Idempotency-Key header when not provided', async () => {
    const { c, calls } = client(() => jsonResponse(invoicePayload(), 201))
    await c.createInvoice({ amount: '1', currency: 'USDT', network: 'tron' })
    const headers = calls[0]!.init.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBeUndefined()
  })
})

describe('baseUrl normalisation', () => {
  it.each([
    ['http://x/'],
    ['http://x'],
    ['http://x/api/v1'],
    ['http://x/api/v1/'],
  ])('%s produces http://x/api/v1/invoices', async (baseUrl) => {
    const { calls } = await (async () => {
      const { fn, calls } = fakeFetch(() => jsonResponse(invoicePayload()))
      const c = new CryptoPay({ apiKey: 'cp_live_test', baseUrl, fetch: fn })
      await c.getInvoice(invoiceFixture.id)
      return { calls }
    })()
    expect(calls[0]!.url).toBe(`http://x/api/v1/invoices/${invoiceFixture.id}`)
  })
})

describe('listInvoices', () => {
  it('serialises filters into the query string and omits undefined', async () => {
    const { c, calls } = client(() => jsonResponse(paginatedPayload([invoiceFixture], { total: 42, last_page: 3 })))

    const result = await c.listInvoices({
      status: 'pending',
      network: 'tron',
      per_page: 25,
      page: undefined,
      external_id: undefined,
    })

    const url = new URL(calls[0]!.url)
    expect(url.pathname).toBe('/api/v1/invoices')
    expect(url.searchParams.get('status')).toBe('pending')
    expect(url.searchParams.get('network')).toBe('tron')
    expect(url.searchParams.get('per_page')).toBe('25')
    expect(url.searchParams.has('page')).toBe(false)
    expect(url.searchParams.has('external_id')).toBe(false)

    expect(result.data).toEqual([invoiceFixture])
    expect(result.meta.total).toBe(42)
    expect(result.meta.last_page).toBe(3)
  })
})

describe('cancelInvoice', () => {
  it('POSTs to the cancel URL', async () => {
    const { c, calls } = client(() => jsonResponse(invoicePayload({ status: 'cancelled' })))
    const invoice = await c.cancelInvoice(invoiceFixture.id)
    expect(calls[0]!.url).toBe(`http://localhost:8095/api/v1/invoices/${invoiceFixture.id}/cancel`)
    expect(calls[0]!.init.method).toBe('POST')
    expect(invoice.status).toBe('cancelled')
  })

  it('throws ApiError with code invalid_state and status 409', async () => {
    const { c } = client(() => errorResponse('invalid_state', 'Invoice cannot be cancelled', {}, 409))
    await expect(c.cancelInvoice(invoiceFixture.id)).rejects.toMatchObject({
      code: 'invalid_state',
      status: 409,
    })
  })
})

describe('validation errors (422)', () => {
  it('sets isValidationError and exposes details.amount', async () => {
    const { c } = client(() =>
      errorResponse('validation_error', 'The given data was invalid.', { amount: ['The amount field is required.'] }, 422),
    )
    try {
      await c.createInvoice({ amount: '', currency: 'USDT', network: 'tron' })
      expect.unreachable('should have thrown')
    } catch (err) {
      expect(err).toBeInstanceOf(ApiError)
      const apiErr = err as ApiError
      expect(apiErr.isValidationError).toBe(true)
      expect(apiErr.status).toBe(422)
      expect(apiErr.details.amount).toEqual(['The amount field is required.'])
    }
  })
})

describe('auth errors (401)', () => {
  it('surfaces code unauthenticated', async () => {
    const { c } = client(() => errorResponse('unauthenticated', 'Invalid or revoked API key.', {}, 401))
    await expect(c.me()).rejects.toMatchObject({ code: 'unauthenticated', status: 401 })
  })
})

describe('non-JSON 5xx body', () => {
  it('falls back to code server_error with raw body in details', async () => {
    const { c } = client(() => textResponse('<html>Internal Server Error</html>', 500))
    try {
      await c.me()
      expect.unreachable('should have thrown')
    } catch (err) {
      const apiErr = err as ApiError
      expect(apiErr.code).toBe('server_error')
      expect(apiErr.status).toBe(500)
      expect(apiErr.details.body).toContain('Internal Server Error')
    }
  })
})

describe('unwrapping data envelopes', () => {
  it('balances() returns { data, totals }', async () => {
    const { c } = client(() =>
      jsonResponse({ data: [balanceFixture], totals: { USDT: { available: '100', pending: '0' } } }),
    )
    const result = await c.balances()
    expect(result.data).toEqual([balanceFixture])
    expect(result.totals.USDT).toEqual({ available: '100', pending: '0' })
  })

  it('networks() unwraps data', async () => {
    const { c } = client(() => jsonResponse({ data: [networkFixture] }))
    const result = await c.networks()
    expect(result).toEqual([networkFixture])
  })

  it('tokens() unwraps data', async () => {
    const { c } = client(() => jsonResponse({ data: [tokenFixture] }))
    const result = await c.tokens()
    expect(result).toEqual([tokenFixture])
  })

  it('customerHoldings() unwraps data', async () => {
    const { c } = client(() => jsonResponse({ data: [{ token: tokenFixture, customer_id: 'cust_1', amount: '10', updated_at: '2026-09-02T00:00:00Z' }] }))
    const result = await c.customerHoldings('cust_1')
    expect(result).toHaveLength(1)
    expect(result[0]!.amount).toBe('10')
  })

  it('me() unwraps data', async () => {
    const { c } = client(() =>
      jsonResponse({
        data: {
          id: 'm_1',
          name: 'Merchant',
          email: 'm@example.com',
          webhook_url: null,
          is_active: true,
          settings: {},
          underpayment_tolerance: '0.01',
          created_at: '2026-09-02T00:00:00Z',
          balances: [balanceFixture],
          api_keys: {},
          webhook: { url: null, configured: false, events: [], signature_header: 'X-CryptoPay-Signature' },
          api_key: null,
        },
      }),
    )
    const account = await c.me()
    expect(account.id).toBe('m_1')
    expect(account.balances).toEqual([balanceFixture])
  })

  it('createTokenPurchase() parses the UNWRAPPED { purchase, invoice }', async () => {
    const { c, calls } = client(() =>
      jsonResponse({ purchase: tokenPurchaseFixture, invoice: invoiceFixture }, 201),
    )
    const result = await c.createTokenPurchase({
      token_id: tokenFixture.id,
      token_amount: '200',
      currency: 'USDT',
      network: 'tron',
      customer_id: 'cust_1',
    })
    expect(calls[0]!.url).toBe('http://localhost:8095/api/v1/token-purchases')
    expect(result.purchase).toEqual(tokenPurchaseFixture)
    expect(result.invoice).toEqual(invoiceFixture)
  })
})

describe('path segment encoding', () => {
  it("customerHoldings('a/b c') URL-encodes the segment", async () => {
    const { c, calls } = client(() => jsonResponse({ data: [] }))
    await c.customerHoldings('a/b c')
    expect(calls[0]!.url).toBe('http://localhost:8095/api/v1/customers/a%2Fb%20c/holdings')
  })
})

describe('timeouts and network errors', () => {
  it('wraps fetch rejection in CryptoPayError, preserving cause', async () => {
    const cause = new Error('boom')
    const rejectingFetch = vi.fn(async () => {
      throw cause
    }) as unknown as typeof globalThis.fetch
    const c = new CryptoPay({ apiKey: 'cp_live_test', baseUrl: 'http://localhost:8095', fetch: rejectingFetch })
    try {
      await c.me()
      expect.unreachable('should have thrown')
    } catch (err) {
      expect(err).toMatchObject({ name: 'CryptoPayError' })
      expect((err as Error).cause).toBe(cause)
    }
  })

  it('throws CryptoPayError with a timeout message when the request times out', async () => {
    const timeoutFetch = vi.fn(async (_url: Parameters<typeof globalThis.fetch>[0], init?: RequestInit) => {
      const signal = init?.signal
      return new Promise<Response>((_resolve, reject) => {
        if (signal) {
          signal.addEventListener('abort', () => {
            const reason = (signal as AbortSignal).reason
            reject(reason instanceof Error ? reason : new Error('aborted'))
          })
        }
      })
    }) as unknown as typeof globalThis.fetch

    const c = new CryptoPay({ apiKey: 'cp_live_test', baseUrl: 'http://localhost:8095', fetch: timeoutFetch, timeout: 10 })
    await expect(c.me()).rejects.toMatchObject({ name: 'CryptoPayError' })
    await expect(c.me()).rejects.toThrow(/timed out/i)
  })
})

describe('transactions()', () => {
  it('returns a Paginated<Transaction>', async () => {
    const { c } = client(() => jsonResponse(paginatedPayload([], { total: 0 })))
    const result = await c.transactions({ network: 'bsc' })
    expect(result.data).toEqual([])
    expect(result.meta.total).toBe(0)
  })
})
