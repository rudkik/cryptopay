// Пример: приём вебхуков CryptoPay без каких-либо зависимостей (чистый node:http).
//
// Запуск:
//   CRYPTOPAY_WEBHOOK_SECRET=whsec_... node examples/webhook-server.mjs
//
// Слушает POST /webhooks/cryptopay на порту 3000 (переменная PORT), проверяет подпись
// через verifyWebhook() и печатает разобранное событие.

import { createServer } from 'node:http'
import { SignatureError, verifyWebhook } from '../dist/index.js'

// Никакого значения по умолчанию: пустой секрет означал бы, что подпись
// подделает кто угодно, а захардкоженный дефолт рано или поздно уедет в прод.
const secret = process.env.CRYPTOPAY_WEBHOOK_SECRET
if (!secret) {
  console.error('Set CRYPTOPAY_WEBHOOK_SECRET before starting this server.')
  process.exit(1)
}
const port = Number(process.env.PORT ?? 3000)

/** Тело вебхука — небольшой JSON; всё сверх лимита обрывается, а не буферизуется. */
const MAX_BODY_BYTES = 1024 * 1024

function readRawBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []
    let received = 0
    req.on('data', (chunk) => {
      received += chunk.length
      if (received > MAX_BODY_BYTES) {
        reject(new Error('request body too large'))
        req.destroy()
        return
      }
      chunks.push(chunk)
    })
    req.on('end', () => resolve(Buffer.concat(chunks)))
    req.on('error', reject)
  })
}

const server = createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/webhooks/cryptopay') {
    res.writeHead(404, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ error: { code: 'not_found', message: 'Not found', details: {} } }))
    return
  }

  const rawBody = await readRawBody(req)

  try {
    const event = verifyWebhook(rawBody, req.headers, secret)
    console.log(`[webhook] ${event.event} (delivery=${event.deliveryId}) isPaid=${event.isPaid}`)
    if (event.invoice) {
      console.log(`  invoice ${event.invoice.id}: ${event.invoice.status}, ${event.invoice.amount} ${event.invoice.currency}`)
    }

    res.writeHead(200, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ received: true }))
  } catch (err) {
    if (err instanceof SignatureError) {
      console.error('[webhook] signature verification failed:', err.message)
      res.writeHead(400, { 'Content-Type': 'application/json' })
      // Причина — в лог, наружу общий текст.
      res.end(
        JSON.stringify({
          error: { code: 'invalid_signature', message: 'Invalid webhook signature.', details: {} },
        }),
      )
      return
    }
    throw err
  }
})

server.listen(port, () => {
  console.log(`CryptoPay webhook example listening on http://localhost:${port}/webhooks/cryptopay`)
})
