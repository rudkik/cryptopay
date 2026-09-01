import type { DocGroup } from './docsTypes'

/**
 * Merchant API reference, transcribed from SPEC §6.1–6.3.
 * Kept as a typed structure (no markdown parser) so it type-checks with the rest of the app.
 */
export const API_DOCS: DocGroup[] = [
  {
    id: 'getting-started',
    title: 'Getting started',
    sections: [
      {
        id: 'overview',
        title: 'Overview',
        blocks: [
          {
            kind: 'text',
            value:
              'CryptoPay accepts USDT and USDC on Ethereum (ERC-20), BNB Smart Chain (BEP-20) and Tron (TRC-20). You create an invoice, we derive a dedicated deposit address, watch the chain for incoming transfers, and notify you with a signed webhook once the payment is confirmed.',
          },
          {
            kind: 'text',
            value:
              'Every amount in the API is a decimal string in human-readable units (for example `"100.5"`), never a float. Parse them with a decimal library (bcmath, BigNumber, Decimal) to avoid rounding errors.',
          },
          {
            kind: 'table',
            headers: ['Network', 'Code', 'Standard', 'Default confirmations'],
            rows: [
              ['Ethereum', 'ethereum', 'ERC-20', '12'],
              ['BNB Smart Chain', 'bsc', 'BEP-20', '15'],
              ['Tron', 'tron', 'TRC-20', '19'],
            ],
          },
          {
            kind: 'callout',
            tone: 'info',
            title: 'Base URL',
            value: 'All merchant endpoints live under `/api/v1` on your CryptoPay host.',
          },
        ],
      },
      {
        id: 'authentication',
        title: 'Authentication',
        blocks: [
          {
            kind: 'text',
            value:
              'Authenticate with an API key issued in the admin console. Keys use the format `cp_live_<40 hex>` and are shown only once at creation — the server stores a SHA-256 hash.',
          },
          {
            kind: 'code',
            language: 'bash',
            title: 'Authenticated request',
            code: `curl https://pay.example.com/api/v1/me \\
  -H "Authorization: Bearer cp_live_0123456789abcdef0123456789abcdef01234567"`,
          },
          {
            kind: 'callout',
            tone: 'warning',
            title: 'Rate limit',
            value:
              '120 requests per minute per key. Exceeding it returns `429` with the `rate_limited` error code.',
          },
        ],
      },
      {
        id: 'errors',
        title: 'Error envelope',
        blocks: [
          {
            kind: 'text',
            value: 'Every failure returns the same envelope, with an HTTP status matching the code.',
          },
          {
            kind: 'code',
            language: 'json',
            code: `{
  "error": {
    "code": "validation_error",
    "message": "The given data was invalid.",
    "details": {
      "amount": ["The amount must be greater than 0."]
    }
  }
}`,
          },
          {
            kind: 'table',
            headers: ['Code', 'HTTP', 'Meaning'],
            rows: [
              ['unauthenticated', '401', 'Missing, revoked or malformed API key'],
              ['forbidden', '403', 'The key cannot access this resource'],
              ['not_found', '404', 'No such resource'],
              ['validation_error', '422', 'Request body failed validation — see `details`'],
              ['invalid_state', '409', 'The action conflicts with the current status'],
              ['rate_limited', '429', 'Too many requests for this key'],
              ['server_error', '500', 'Unexpected failure — safe to retry'],
            ],
          },
        ],
      },
      {
        id: 'idempotency',
        title: 'Idempotency',
        blocks: [
          {
            kind: 'text',
            value:
              'Send an `Idempotency-Key` header on `POST /api/v1/invoices` and `POST /api/v1/token-purchases`. The first response is cached for 24 hours and replayed for any repeat of the same key, so a network retry can never create two invoices.',
          },
          {
            kind: 'code',
            language: 'bash',
            code: `curl -X POST https://pay.example.com/api/v1/invoices \\
  -H "Authorization: Bearer $CRYPTOPAY_KEY" \\
  -H "Idempotency-Key: order-1042-attempt-1" \\
  -H "Content-Type: application/json" \\
  -d '{"amount":"100.00","currency":"USDT","network":"tron"}'`,
          },
        ],
      },
    ],
  },
  {
    id: 'invoices',
    title: 'Invoices',
    sections: [
      {
        id: 'invoice-object',
        title: 'The Invoice object',
        blocks: [
          {
            kind: 'text',
            value:
              'The same object is returned from every invoice endpoint and embedded in every webhook payload.',
          },
          {
            kind: 'code',
            language: 'json',
            code: `{
  "id": "9b1f0f4c-6f2f-4e1e-9a3c-2f5d1c7c1a10",
  "type": "payment",
  "external_id": "order-1",
  "status": "pending",
  "is_paid": false,
  "currency": "USDT",
  "network": "tron",
  "amount": "100.000000",
  "amount_received": "0",
  "amount_confirmed": "0",
  "address": "TXk8rQSAvPvBBcBjRYaZmzcJdMdaGCNEjm",
  "payment_url": "https://pay.example.com/pay/9b1f0f4c-6f2f-4e1e-9a3c-2f5d1c7c1a10",
  "qr_payload": "TXk8rQSAvPvBBcBjRYaZmzcJdMdaGCNEjm",
  "description": "Order #1",
  "customer_email": null,
  "customer_id": null,
  "metadata": {},
  "success_url": null,
  "cancel_url": null,
  "expires_at": "2026-01-01T12:00:00Z",
  "paid_at": null,
  "created_at": "2026-01-01T11:00:00Z",
  "transactions": [],
  "token_purchase": null
}`,
          },
          {
            kind: 'text',
            value:
              '`qr_payload` is an EIP-681 URI on EVM networks (`ethereum:{contract}@{chainId}/transfer?address={address}&uint256={amount_raw}`) and the plain address on Tron.',
          },
        ],
      },
      {
        id: 'invoice-statuses',
        title: 'Statuses',
        blocks: [
          {
            kind: 'table',
            headers: ['Status', 'Meaning'],
            rows: [
              ['pending', 'Waiting for the first transfer to the deposit address'],
              ['confirming', 'At least one transfer detected, waiting for confirmations'],
              ['paid', 'Confirmed amount covers the invoice'],
              ['overpaid', 'Confirmed amount exceeds the invoice — still counts as paid'],
              ['partially_paid', 'Expired with a confirmed amount below the invoice'],
              ['expired', 'Expired with nothing received'],
              ['cancelled', 'Cancelled through the API or the admin console'],
            ],
          },
          {
            kind: 'callout',
            tone: 'info',
            title: 'is_paid',
            value: 'Treat an order as settled when `is_paid` is true — it covers both `paid` and `overpaid`.',
          },
          {
            kind: 'callout',
            tone: 'warning',
            title: 'Late payments',
            value:
              'Funds sent to an expired or cancelled invoice are still credited to your balance, and the invoice moves to `paid` or `partially_paid` with the matching webhook. Handle these events idempotently.',
          },
        ],
      },
      {
        id: 'create-invoice',
        title: 'Create an invoice',
        blocks: [
          { kind: 'endpoint', method: 'POST', path: '/api/v1/invoices', summary: 'Create a payment invoice' },
          {
            kind: 'table',
            headers: ['Field', 'Type', 'Notes'],
            rows: [
              ['amount', 'string', 'Required, greater than 0'],
              ['currency', 'string', 'Required — `USDT` or `USDC`'],
              ['network', 'string', 'Required — `ethereum`, `bsc` or `tron`'],
              ['external_id', 'string', 'Your order reference'],
              ['description', 'string', 'Shown on the hosted checkout'],
              ['customer_email', 'string', 'Optional'],
              ['customer_id', 'string', 'Your customer reference'],
              ['metadata', 'object', 'Returned untouched in webhooks'],
              ['success_url', 'string', 'Redirect target after payment'],
              ['cancel_url', 'string', 'Redirect target on cancel'],
              ['expires_in', 'integer', 'Seconds — default 3600, max 86400'],
            ],
          },
          {
            kind: 'code',
            language: 'bash',
            title: 'curl',
            code: `curl -X POST https://pay.example.com/api/v1/invoices \\
  -H "Authorization: Bearer $CRYPTOPAY_KEY" \\
  -H "Idempotency-Key: order-1" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": "100.00",
    "currency": "USDT",
    "network": "tron",
    "external_id": "order-1",
    "description": "Order #1",
    "success_url": "https://shop.example.com/thanks",
    "expires_in": 3600
  }'`,
          },
          {
            kind: 'code',
            language: 'php',
            title: 'PHP',
            code: `$response = Http::withToken(env('CRYPTOPAY_KEY'))
    ->withHeaders(['Idempotency-Key' => 'order-1'])
    ->post('https://pay.example.com/api/v1/invoices', [
        'amount'      => '100.00',
        'currency'    => 'USDT',
        'network'     => 'tron',
        'external_id' => 'order-1',
    ])
    ->throw()
    ->json();

return redirect($response['payment_url']);`,
          },
          {
            kind: 'code',
            language: 'javascript',
            title: 'Node.js',
            code: `const res = await fetch('https://pay.example.com/api/v1/invoices', {
  method: 'POST',
  headers: {
    Authorization: \`Bearer \${process.env.CRYPTOPAY_KEY}\`,
    'Idempotency-Key': 'order-1',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    amount: '100.00',
    currency: 'USDT',
    network: 'tron',
    external_id: 'order-1',
  }),
})

if (!res.ok) throw new Error((await res.json()).error.message)
const invoice = await res.json()`,
          },
          {
            kind: 'code',
            language: 'python',
            title: 'Python',
            code: `import os, requests

res = requests.post(
    "https://pay.example.com/api/v1/invoices",
    headers={
        "Authorization": f"Bearer {os.environ['CRYPTOPAY_KEY']}",
        "Idempotency-Key": "order-1",
    },
    json={
        "amount": "100.00",
        "currency": "USDT",
        "network": "tron",
        "external_id": "order-1",
    },
    timeout=15,
)
res.raise_for_status()
invoice = res.json()`,
          },
        ],
      },
      {
        id: 'list-invoices',
        title: 'List and read invoices',
        blocks: [
          { kind: 'endpoint', method: 'GET', path: '/api/v1/invoices', summary: 'Paginated list' },
          { kind: 'endpoint', method: 'GET', path: '/api/v1/invoices/{id}', summary: 'Single invoice' },
          {
            kind: 'text',
            value:
              'Filters: `status`, `external_id`, `network`, `currency`, `from`, `to`, `per_page` (max 100).',
          },
          {
            kind: 'code',
            language: 'json',
            title: 'Paginated response',
            code: `{
  "data": [ { "id": "…", "status": "paid" } ],
  "meta": {
    "current_page": 1,
    "last_page": 4,
    "per_page": 25,
    "total": 87
  }
}`,
          },
        ],
      },
      {
        id: 'cancel-invoice',
        title: 'Cancel an invoice',
        blocks: [
          {
            kind: 'endpoint',
            method: 'POST',
            path: '/api/v1/invoices/{id}/cancel',
            summary: 'Cancel a pending invoice',
          },
          {
            kind: 'text',
            value:
              'Only invoices in `pending` can be cancelled; anything else returns `409 invalid_state`.',
          },
          {
            kind: 'code',
            language: 'bash',
            code: `curl -X POST https://pay.example.com/api/v1/invoices/$ID/cancel \\
  -H "Authorization: Bearer $CRYPTOPAY_KEY"`,
          },
        ],
      },
    ],
  },
  {
    id: 'resources',
    title: 'Other resources',
    sections: [
      {
        id: 'networks-balances',
        title: 'Networks, balances and transactions',
        blocks: [
          { kind: 'endpoint', method: 'GET', path: '/api/v1/networks', summary: 'Enabled networks and token contracts' },
          { kind: 'endpoint', method: 'GET', path: '/api/v1/balances', summary: 'Balances per currency and network' },
          { kind: 'endpoint', method: 'GET', path: '/api/v1/transactions', summary: 'Detected transfers' },
          {
            kind: 'code',
            language: 'json',
            title: 'GET /api/v1/balances',
            code: `{
  "data": [
    { "currency": "USDT", "network": "tron", "available": "1250.50", "pending": "100.00" },
    { "currency": "USDC", "network": "bsc",  "available": "0",       "pending": "0" }
  ],
  "totals": [
    { "currency": "USDT", "available": "1250.50", "pending": "100.00" }
  ]
}`,
          },
          {
            kind: 'text',
            value:
              '`available` counts only confirmed transactions credited to the ledger. `pending` is the sum of detected-but-unconfirmed transfers.',
          },
        ],
      },
      {
        id: 'token-sale',
        title: 'Token sale',
        blocks: [
          { kind: 'endpoint', method: 'GET', path: '/api/v1/tokens', summary: 'Your active token products' },
          { kind: 'endpoint', method: 'POST', path: '/api/v1/token-purchases', summary: 'Start a purchase' },
          { kind: 'endpoint', method: 'GET', path: '/api/v1/token-purchases/{id}', summary: 'Read a purchase' },
          {
            kind: 'endpoint',
            method: 'GET',
            path: '/api/v1/customers/{customer_id}/holdings',
            summary: 'Customer holdings',
          },
          {
            kind: 'text',
            value:
              'Send either `token_amount` or `pay_amount` — the other is derived at the price captured when the purchase is created. USDT and USDC are both treated as 1 USD.',
          },
          {
            kind: 'code',
            language: 'bash',
            code: `curl -X POST https://pay.example.com/api/v1/token-purchases \\
  -H "Authorization: Bearer $CRYPTOPAY_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{
    "token_id": "1f7c…",
    "token_amount": "1000",
    "currency": "USDT",
    "network": "bsc",
    "customer_id": "user-42"
  }'`,
          },
          {
            kind: 'code',
            language: 'json',
            title: 'Response 201',
            code: `{
  "purchase": {
    "id": "…",
    "token_amount": "1000",
    "pay_amount": "250.00",
    "currency": "USDT",
    "status": "pending"
  },
  "invoice": { "id": "…", "payment_url": "https://pay.example.com/pay/…" }
}`,
          },
        ],
      },
      {
        id: 'hosted-checkout',
        title: 'Hosted checkout',
        blocks: [
          {
            kind: 'text',
            value:
              'Redirect the buyer to the `payment_url` returned with the invoice. The page shows the amount, network, deposit address, a QR code, an expiry countdown and live confirmation progress, and polls its status every 5 seconds.',
          },
          {
            kind: 'list',
            ordered: true,
            items: [
              'Create the invoice with `success_url` and `cancel_url`.',
              'Redirect the buyer to `payment_url`.',
              'The buyer sends the exact amount to the displayed address.',
              'When the payment confirms, the page shows a receipt and links to `success_url`.',
              'Fulfil the order from the `invoice.paid` webhook — never from the redirect alone.',
            ],
          },
          { kind: 'endpoint', method: 'GET', path: '/api/public/invoices/{id}', summary: 'Public status (no auth)' },
          {
            kind: 'callout',
            tone: 'warning',
            title: 'Never trust the redirect',
            value:
              'A buyer can reach `success_url` without paying. The webhook (or a server-side `GET /api/v1/invoices/{id}`) is the only authoritative confirmation.',
          },
        ],
      },
    ],
  },
  {
    id: 'webhooks',
    title: 'Webhooks',
    sections: [
      {
        id: 'webhook-events',
        title: 'Events and delivery',
        blocks: [
          {
            kind: 'text',
            value:
              'CryptoPay POSTs a JSON body to your merchant webhook URL. Any 2xx response counts as success; anything else is retried after 1m, 5m, 30m, 2h, 6h and 24h (6 attempts), and can be replayed manually from the admin console.',
          },
          {
            kind: 'table',
            headers: ['Event', 'Fires when'],
            rows: [
              ['invoice.confirming', 'The first transfer is detected on the deposit address'],
              ['invoice.paid', 'The confirmed amount covers the invoice'],
              ['invoice.overpaid', 'The confirmed amount exceeds the invoice'],
              ['invoice.partially_paid', 'The invoice expired underpaid'],
              ['invoice.expired', 'The invoice expired with nothing received'],
              ['invoice.cancelled', 'The invoice was cancelled'],
              ['token_purchase.completed', 'A token purchase settled and holdings were credited'],
            ],
          },
          {
            kind: 'code',
            language: 'json',
            title: 'Request body',
            code: `{
  "id": "3f2a…",
  "event": "invoice.paid",
  "created_at": "2026-01-01T12:03:11Z",
  "data": {
    "invoice": { "id": "…", "status": "paid", "is_paid": true },
    "token_purchase": null
  }
}`,
          },
          {
            kind: 'table',
            headers: ['Header', 'Value'],
            rows: [
              ['X-CryptoPay-Event', 'Event name, e.g. `invoice.paid`'],
              ['X-CryptoPay-Delivery', 'Delivery UUID — use it to deduplicate'],
              ['X-CryptoPay-Timestamp', 'Unix seconds, part of the signed payload'],
              ['X-CryptoPay-Signature', '`sha256=<hex hmac>` over `timestamp + "." + raw_body`'],
            ],
          },
        ],
      },
      {
        id: 'webhook-signature',
        title: 'Verifying the signature',
        blocks: [
          {
            kind: 'text',
            value:
              'Compute `hmac_sha256(webhook_secret, timestamp + "." + raw_body)` and compare it to the header in constant time. Always verify against the raw request body — re-encoding the JSON changes the bytes and breaks the signature.',
          },
          {
            kind: 'code',
            language: 'php',
            title: 'PHP',
            code: `<?php

function verifyCryptoPayWebhook(
    string $rawBody,
    string $timestamp,
    string $signatureHeader,
    string $secret
): bool {
    // Reject anything older than 5 minutes to stop replays.
    if (abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

    return hash_equals($expected, $signatureHeader);
}

$raw = file_get_contents('php://input');
$ok  = verifyCryptoPayWebhook(
    $raw,
    $_SERVER['HTTP_X_CRYPTOPAY_TIMESTAMP'] ?? '',
    $_SERVER['HTTP_X_CRYPTOPAY_SIGNATURE'] ?? '',
    getenv('CRYPTOPAY_WEBHOOK_SECRET')
);

if (! $ok) {
    http_response_code(400);
    exit;
}

$payload = json_decode($raw, true);
// fulfil($payload['data']['invoice']);
http_response_code(200);`,
          },
          {
            kind: 'code',
            language: 'javascript',
            title: 'Node.js (Express)',
            code: `import crypto from 'node:crypto'
import express from 'express'

const app = express()
const SECRET = process.env.CRYPTOPAY_WEBHOOK_SECRET

// The raw body is required — do not use express.json() on this route.
app.post('/webhooks/cryptopay', express.raw({ type: 'application/json' }), (req, res) => {
  const timestamp = req.get('X-CryptoPay-Timestamp') ?? ''
  const signature = req.get('X-CryptoPay-Signature') ?? ''
  const raw = req.body.toString('utf8')

  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) {
    return res.status(400).end()
  }

  const expected =
    'sha256=' + crypto.createHmac('sha256', SECRET).update(\`\${timestamp}.\${raw}\`).digest('hex')

  const a = Buffer.from(expected)
  const b = Buffer.from(signature)
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return res.status(400).end()
  }

  const payload = JSON.parse(raw)
  // await fulfil(payload.data.invoice)
  res.status(200).end()
})`,
          },
          {
            kind: 'code',
            language: 'python',
            title: 'Python (Flask)',
            code: `import hmac, hashlib, os, time
from flask import Flask, request, abort

app = Flask(__name__)
SECRET = os.environ["CRYPTOPAY_WEBHOOK_SECRET"].encode()


@app.post("/webhooks/cryptopay")
def cryptopay_webhook():
    raw = request.get_data()  # bytes, exactly as received
    timestamp = request.headers.get("X-CryptoPay-Timestamp", "")
    signature = request.headers.get("X-CryptoPay-Signature", "")

    if abs(time.time() - int(timestamp or 0)) > 300:
        abort(400)

    signed = f"{timestamp}.".encode() + raw
    expected = "sha256=" + hmac.new(SECRET, signed, hashlib.sha256).hexdigest()

    if not hmac.compare_digest(expected, signature):
        abort(400)

    payload = request.get_json()
    # fulfil(payload["data"]["invoice"])
    return "", 200`,
          },
          {
            kind: 'callout',
            tone: 'success',
            title: 'Make handlers idempotent',
            value:
              'Retries and late payments mean the same event can arrive more than once. Deduplicate on `X-CryptoPay-Delivery`, or make fulfilment safe to repeat for a given invoice id.',
          },
        ],
      },
    ],
  },
]
