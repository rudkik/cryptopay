// Пример: полный цикл работы со счётом через @cryptopay/sdk.
//
// Запуск:
//   node examples/create-invoice.mjs
//
// Переменные окружения:
//   CRYPTOPAY_API_KEY  — по умолчанию тестовый ключ из docker-compose (cp_live_ab12...)
//   CRYPTOPAY_BASE_URL — по умолчанию http://localhost:8095
//
// Важно: пример импортирует собранный пакет из ../dist/index.js — сначала выполните `npm run build`.

import { ApiError, CryptoPay } from '../dist/index.js'

const apiKey = process.env.CRYPTOPAY_API_KEY ?? 'cp_live_ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12'
const baseUrl = process.env.CRYPTOPAY_BASE_URL ?? 'http://localhost:8095'

const cryptoPay = new CryptoPay({ apiKey, baseUrl })

function idempotencyKey() {
  return `example-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

async function main() {
  console.log(`> CryptoPay SDK example, baseUrl=${baseUrl}`)

  console.log('\n1. Создаём счёт (createInvoice)...')
  const invoice = await cryptoPay.createInvoice(
    {
      amount: '12.5',
      currency: 'USDT',
      network: 'tron',
      external_id: `sdk-example-${Date.now()}`,
      description: 'SDK smoke test invoice',
    },
    idempotencyKey(),
  )
  console.log('   id:          ', invoice.id)
  console.log('   address:     ', invoice.address)
  console.log('   payment_url: ', invoice.payment_url)
  console.log('   status:      ', invoice.status)

  console.log('\n2. Получаем счёт обратно (getInvoice)...')
  const fetched = await cryptoPay.getInvoice(invoice.id)
  console.log('   status:', fetched.status, ' amount:', fetched.amount, fetched.currency)

  console.log('\n3. Список счетов (listInvoices)...')
  const list = await cryptoPay.listInvoices({ per_page: 5 })
  console.log(`   получено ${list.data.length} из ${list.meta.total} (страница ${list.meta.current_page}/${list.meta.last_page})`)

  console.log('\n4. Балансы (balances)...')
  const balances = await cryptoPay.balances()
  console.log('   записей:', balances.data.length)
  console.log('   totals:', JSON.stringify(balances.totals))

  console.log('\n5. Сети (networks)...')
  const networks = await cryptoPay.networks()
  console.log('  ', networks.map((n) => n.code).join(', '))

  console.log('\n6. Отменяем счёт (cancelInvoice)...')
  const cancelled = await cryptoPay.cancelInvoice(invoice.id)
  console.log('   финальный статус:', cancelled.status)
}

main().catch((err) => {
  if (err instanceof ApiError) {
    console.error(`\nApiError: [${err.status}] ${err.code} — ${err.message}`)
    console.error('details:', JSON.stringify(err.details))
  } else {
    console.error('\nОшибка:', err)
  }
  process.exitCode = 1
})
