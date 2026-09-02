import { createHmac } from 'node:crypto'
import { describe, expect, it, vi } from 'vitest'
import { SignatureError, cryptoPayWebhook, signWebhook, verifyWebhook } from '../src/index.js'
import type { WebhookPayload } from '../src/types.js'
import { invoiceFixture } from './fixtures.js'

const SECRET = 'whsec_test_secret'

function makePayload(overrides: Partial<WebhookPayload> = {}): WebhookPayload {
  return {
    id: 'delivery-uuid-1',
    event: 'invoice.paid',
    created_at: '2026-09-02T05:14:00+00:00',
    data: { invoice: invoiceFixture, token_purchase: null },
    ...overrides,
  }
}

function sign(secret: string, timestamp: number | string, body: string): string {
  const hex = createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex')
  return `sha256=${hex}`
}

function headersFor(timestamp: number | string, signature: string, deliveryId = 'delivery-uuid-1') {
  return {
    'X-CryptoPay-Event': 'invoice.paid',
    'X-CryptoPay-Delivery': deliveryId,
    'X-CryptoPay-Timestamp': String(timestamp),
    'X-CryptoPay-Signature': signature,
  }
}

describe('signWebhook', () => {
  it('matches the PHP backend formula: sha256=hmac_sha256(secret, timestamp + "." + body)', () => {
    const body = '{"id":"x","event":"invoice.paid"}'
    const timestamp = 1893456000
    const expectedHex = createHmac('sha256', SECRET).update(`${timestamp}.${body}`).digest('hex')

    const actual = signWebhook(SECRET, timestamp, body)

    expect(actual).toBe(`sha256=${expectedHex}`)
    expect(actual).toMatch(/^sha256=[0-9a-f]{64}$/)
  })

  it('produces the SAME signature for equivalent Buffer and string bodies', () => {
    const body = '{"hello":"мир"}' // includes non-ASCII to catch encoding bugs
    const fromString = signWebhook(SECRET, 1000, body)
    const fromBuffer = signWebhook(SECRET, 1000, Buffer.from(body, 'utf8'))
    expect(fromBuffer).toBe(fromString)
  })
})

describe('verifyWebhook', () => {
  it('returns a WebhookEvent with isPaid=true for a validly signed invoice.paid payload', () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)

    const event = verifyWebhook(body, headersFor(timestamp, signature), SECRET)

    expect(event.id).toBe(payload.id)
    expect(event.event).toBe('invoice.paid')
    expect(event.deliveryId).toBe('delivery-uuid-1')
    expect(event.invoice).toEqual(invoiceFixture)
    expect(event.tokenPurchase).toBeNull()
    expect(event.isPaid).toBe(true)
    expect(event.payload).toEqual(payload)
  })

  it('isPaid is true for invoice.overpaid too', () => {
    const payload = makePayload({ event: 'invoice.overpaid' })
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    const event = verifyWebhook(body, headersFor(timestamp, signature), SECRET)
    expect(event.isPaid).toBe(true)
  })

  it('isPaid is false for other events', () => {
    const payload = makePayload({ event: 'invoice.expired' })
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    const event = verifyWebhook(body, headersFor(timestamp, signature), SECRET)
    expect(event.isPaid).toBe(false)
  })

  it('is case-insensitive for header names', () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    const headers = {
      'x-cryptopay-signature': signature,
      'x-cryptopay-timestamp': String(timestamp),
      'x-cryptopay-delivery': 'delivery-uuid-1',
    }
    const event = verifyWebhook(body, headers, SECRET)
    expect(event.deliveryId).toBe('delivery-uuid-1')
  })

  it('falls back to payload.id when the delivery header is absent', () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    const headers = {
      'X-CryptoPay-Signature': signature,
      'X-CryptoPay-Timestamp': String(timestamp),
    }
    const event = verifyWebhook(body, headers, SECRET)
    expect(event.deliveryId).toBe(payload.id)
  })

  it('throws SignatureError when the signature header is missing', () => {
    const body = JSON.stringify(makePayload())
    expect(() =>
      verifyWebhook(body, { 'X-CryptoPay-Timestamp': String(Math.floor(Date.now() / 1000)) }, SECRET),
    ).toThrow(SignatureError)
  })

  it('throws SignatureError when the timestamp header is missing', () => {
    const body = JSON.stringify(makePayload())
    const signature = sign(SECRET, Math.floor(Date.now() / 1000), body)
    expect(() => verifyWebhook(body, { 'X-CryptoPay-Signature': signature }, SECRET)).toThrow(SignatureError)
  })

  it('throws SignatureError when the timestamp is NaN', () => {
    const body = JSON.stringify(makePayload())
    const signature = sign(SECRET, 'not-a-number', body)
    expect(() =>
      verifyWebhook(body, headersFor('not-a-number', signature), SECRET),
    ).toThrow(SignatureError)
  })

  it('throws SignatureError on a tampered body (signature mismatch)', () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    const tamperedBody = JSON.stringify({ ...payload, event: 'invoice.cancelled' })
    expect(() => verifyWebhook(tamperedBody, headersFor(timestamp, signature), SECRET)).toThrow(SignatureError)
  })

  it('throws SignatureError with the wrong secret', () => {
    const body = JSON.stringify(makePayload())
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    expect(() => verifyWebhook(body, headersFor(timestamp, signature), 'wrong-secret')).toThrow(SignatureError)
  })

  it('throws SignatureError on a stale timestamp beyond tolerance', () => {
    const body = JSON.stringify(makePayload())
    const staleTimestamp = Math.floor(Date.now() / 1000) - 10_000
    const signature = sign(SECRET, staleTimestamp, body)
    expect(() => verifyWebhook(body, headersFor(staleTimestamp, signature), SECRET)).toThrow(SignatureError)
  })

  it('tolerance: 0 disables the staleness check', () => {
    const body = JSON.stringify(makePayload())
    const staleTimestamp = Math.floor(Date.now() / 1000) - 10_000
    const signature = sign(SECRET, staleTimestamp, body)
    const event = verifyWebhook(body, headersFor(staleTimestamp, signature), SECRET, { tolerance: 0 })
    expect(event.id).toBe('delivery-uuid-1')
  })

  it('throws SignatureError for an unparseable JSON body (with a valid signature)', () => {
    const body = 'not json'
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    expect(() => verifyWebhook(body, headersFor(timestamp, signature), SECRET)).toThrow(SignatureError)
  })

  it('accepts a Buffer rawBody identically to the equivalent string', () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)
    const event = verifyWebhook(Buffer.from(body, 'utf8'), headersFor(timestamp, signature), SECRET)
    expect(event.isPaid).toBe(true)
  })
})

describe('cryptoPayWebhook middleware', () => {
  function fakeRes() {
    const res: any = {
      statusCode: 0,
      headersSent: false,
      body: undefined as unknown,
      status(code: number) {
        this.statusCode = code
        return this
      },
      json(payload: unknown) {
        this.body = payload
        this.headersSent = true
        return this
      },
    }
    return res
  }

  it('calls the handler with a valid signature and responds 200 { received: true }', async () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)

    const handler = vi.fn()
    const middleware = cryptoPayWebhook(SECRET, handler)
    const req = { body, headers: headersFor(timestamp, signature) }
    const res = fakeRes()
    const next = vi.fn()

    middleware(req, res, next)
    await vi.waitFor(() => expect(handler).toHaveBeenCalledTimes(1))
    await vi.waitFor(() => expect(res.headersSent).toBe(true))

    expect(handler.mock.calls[0]![0].isPaid).toBe(true)
    expect(res.statusCode).toBe(200)
    expect(res.body).toEqual({ received: true })
    expect(next).not.toHaveBeenCalled()
  })

  it('responds 400 on a bad signature and does not call the handler', async () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const handler = vi.fn()
    const middleware = cryptoPayWebhook(SECRET, handler)
    const req = { body, headers: headersFor(Math.floor(Date.now() / 1000), 'sha256=deadbeef') }
    const res = fakeRes()
    const next = vi.fn()

    middleware(req, res, next)
    await vi.waitFor(() => expect(res.headersSent).toBe(true))

    expect(handler).not.toHaveBeenCalled()
    expect(res.statusCode).toBe(400)
    expect((res.body as any).error.code).toBe('invalid_signature')
  })

  it('responds 400 with the raw-body hint when req.body is a parsed object and no req.rawBody', async () => {
    const handler = vi.fn()
    const middleware = cryptoPayWebhook(SECRET, handler)
    const req = { body: { already: 'parsed' }, headers: headersFor(Math.floor(Date.now() / 1000), 'sha256=x') }
    const res = fakeRes()
    const next = vi.fn()

    middleware(req, res, next)
    await vi.waitFor(() => expect(res.headersSent).toBe(true))

    expect(handler).not.toHaveBeenCalled()
    expect(res.statusCode).toBe(400)
    expect((res.body as any).error.message).toMatch(/express\.raw/)
  })

  it('uses req.rawBody when req.body is not a Buffer/string', async () => {
    const payload = makePayload()
    const body = JSON.stringify(payload)
    const timestamp = Math.floor(Date.now() / 1000)
    const signature = sign(SECRET, timestamp, body)

    const handler = vi.fn()
    const middleware = cryptoPayWebhook(SECRET, handler)
    const req = { body: { already: 'parsed' }, rawBody: body, headers: headersFor(timestamp, signature) }
    const res = fakeRes()
    const next = vi.fn()

    middleware(req, res, next)
    await vi.waitFor(() => expect(handler).toHaveBeenCalledTimes(1))
  })

  it('calls onError instead of the default 400 response when provided', async () => {
    const handler = vi.fn()
    const onError = vi.fn((_err, _req, res) => {
      res.status(422).json({ custom: true })
    })
    const middleware = cryptoPayWebhook(SECRET, handler, { onError })
    const req = { body: 'x', headers: {} }
    const res = fakeRes()
    const next = vi.fn()

    middleware(req, res, next)
    await vi.waitFor(() => expect(onError).toHaveBeenCalledTimes(1))
    expect(res.statusCode).toBe(422)
  })
})
