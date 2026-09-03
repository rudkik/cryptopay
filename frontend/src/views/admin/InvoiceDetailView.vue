<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  ArrowLeft,
  Ban,
  ExternalLink,
  FlaskConical,
  Link2,
  RefreshCw,
  RotateCw,
  Webhook,
} from 'lucide-vue-next'
import AddressDisplay from '@/components/AddressDisplay.vue'
import AmountDisplay from '@/components/AmountDisplay.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CopyButton from '@/components/CopyButton.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import Modal from '@/components/Modal.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import ProgressBar from '@/components/ProgressBar.vue'
import QrCode from '@/components/QrCode.vue'
import Skeleton from '@/components/Skeleton.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
import { invoicesApi } from '@/api/invoices'
import { webhooksApi } from '@/api/webhooks'
import type { Invoice, InvoiceStatus, WebhookDelivery } from '@/api/types'
import { isApiError } from '@/api/http'
import { reportError } from '@/composables/useErrorHandler'
import { toast } from '@/utils/toast'
import { safeUrl } from '@/utils/url'
import {
  compareAmounts,
  formatDateTime,
  formatRelative,
  percentOf,
  subtractAmounts,
  truncateMiddle,
} from '@/utils/format'
import { NETWORK_NAMES } from '@/utils/options'

const props = defineProps<{ id: string }>()

const invoice = ref<Invoice | null>(null)
const loading = ref(true)
const refreshing = ref(false)
const notFound = ref(false)

const cancelOpen = ref(false)
const cancelling = ref(false)

const simulateOpen = ref(false)
const simulating = ref(false)
const simulateAmount = ref('')
const simulateConfirmed = ref(true)

const retryingWebhook = ref<string | null>(null)

const transactions = computed(() => invoice.value?.transactions ?? [])
const webhookDeliveries = computed(() => invoice.value?.webhooks ?? [])

const canCancel = computed(() => invoice.value?.status === 'pending')

/** Hosted-checkout link, accepted only as a plain http(s) URL. */
const paymentUrl = computed(() => safeUrl(invoice.value?.payment_url))

/**
 * The QR must encode exactly the deposit address shown next to it. `qr_payload`
 * is the bare address (Tron) or an EIP-681 URI embedding it (EVM), so it has to
 * contain `invoice.address`; otherwise we encode the displayed address instead.
 */
const qrValue = computed(() => {
  const inv = invoice.value
  if (!inv?.address) return ''
  const payload = inv.qr_payload ?? ''
  return payload.includes(inv.address) ? payload : inv.address
})

const remaining = computed(() =>
  invoice.value ? subtractAmounts(invoice.value.amount, invoice.value.amount_confirmed, true) : '0',
)
const isFullyCovered = computed(() => compareAmounts(remaining.value, '0') <= 0)

const receivedPercent = computed(() =>
  invoice.value ? percentOf(invoice.value.amount_received, invoice.value.amount) : 0,
)
const confirmedPercent = computed(() =>
  invoice.value ? percentOf(invoice.value.amount_confirmed, invoice.value.amount) : 0,
)

/** SPEC §5 lifecycle rendered as a timeline. */
interface TimelineStep {
  key: string
  label: string
  at: string | null
  state: 'done' | 'current' | 'todo' | 'failed'
}

const timeline = computed<TimelineStep[]>(() => {
  const inv = invoice.value
  if (!inv) return []
  const status = inv.status
  const seenTx = transactions.value.length > 0
  const confirmed = transactions.value.some((tx) => tx.status === 'confirmed')

  const terminalBad: InvoiceStatus[] = ['expired', 'cancelled']
  const steps: TimelineStep[] = [
    { key: 'created', label: 'Invoice created', at: inv.created_at, state: 'done' },
    {
      key: 'detected',
      label: 'Payment detected',
      at: seenTx ? (transactions.value[0]?.created_at ?? null) : null,
      state: seenTx ? 'done' : status === 'pending' ? 'current' : 'todo',
    },
    {
      key: 'confirmed',
      label: 'Confirmed on-chain',
      at: confirmed ? inv.paid_at : null,
      state: confirmed ? 'done' : status === 'confirming' ? 'current' : 'todo',
    },
  ]

  if (terminalBad.includes(status)) {
    steps.push({
      key: 'terminal',
      label: status === 'cancelled' ? 'Cancelled' : 'Expired',
      at: inv.expires_at,
      state: 'failed',
    })
  } else if (status === 'partially_paid') {
    steps.push({ key: 'terminal', label: 'Partially paid', at: inv.expires_at, state: 'failed' })
  } else {
    steps.push({
      key: 'settled',
      label: status === 'overpaid' ? 'Settled (overpaid)' : 'Settled',
      at: inv.paid_at,
      state: inv.is_paid ? 'done' : 'todo',
    })
  }
  return steps
})

const txColumns: Column[] = [
  { key: 'tx_hash', label: 'Transaction' },
  { key: 'amount', label: 'Amount', class: 'text-right' },
  { key: 'confirmations', label: 'Confirmations', class: 'text-right' },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Seen', class: 'text-right', hideBelow: 'md' },
]

const webhookColumns: Column[] = [
  { key: 'event', label: 'Event' },
  { key: 'status', label: 'Status' },
  { key: 'attempts', label: 'Attempts', class: 'text-right', hideBelow: 'sm' },
  { key: 'response_code', label: 'Response', class: 'text-right', hideBelow: 'sm' },
  { key: 'next_attempt_at', label: 'Next attempt', class: 'text-right', hideBelow: 'md' },
  { key: 'actions', label: '', class: 'text-right w-px' },
]

async function load(silent = false): Promise<void> {
  if (silent) refreshing.value = true
  else loading.value = true
  try {
    invoice.value = await invoicesApi.get(props.id)
    notFound.value = false
  } catch (error) {
    if (isApiError(error) && error.status === 404) notFound.value = true
    else reportError(error, 'Failed to load the invoice')
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

async function confirmCancel(): Promise<void> {
  cancelling.value = true
  try {
    invoice.value = await invoicesApi.cancel(props.id)
    toast.success('Invoice cancelled')
    cancelOpen.value = false
  } catch (error) {
    reportError(error, 'Could not cancel the invoice')
  } finally {
    cancelling.value = false
  }
}

async function runSimulation(): Promise<void> {
  simulating.value = true
  try {
    invoice.value = await invoicesApi.simulatePayment(props.id, {
      amount: simulateAmount.value.trim() || undefined,
      confirmed: simulateConfirmed.value,
    })
    toast.success('Simulated payment queued', 'The invoice will update as the pipeline runs.')
    simulateOpen.value = false
    window.setTimeout(() => void load(true), 1200)
  } catch (error) {
    // SPEC §6.4: the endpoint only exists when SIMULATION_ENABLED=true.
    if (isApiError(error) && (error.status === 403 || error.status === 404)) {
      toast.error('Simulation disabled', 'Set SIMULATION_ENABLED=true on the backend to use this.')
      simulateOpen.value = false
    } else {
      reportError(error, 'Simulation failed')
    }
  } finally {
    simulating.value = false
  }
}

async function retryWebhook(delivery: WebhookDelivery): Promise<void> {
  retryingWebhook.value = delivery.id
  try {
    await webhooksApi.retry(delivery.id)
    toast.success('Delivery re-queued')
    await load(true)
  } catch (error) {
    reportError(error, 'Could not retry the delivery')
  } finally {
    retryingWebhook.value = null
  }
}

function openSimulate(): void {
  simulateAmount.value = invoice.value ? remaining.value : ''
  simulateConfirmed.value = true
  simulateOpen.value = true
}

onMounted(() => void load())
</script>

<template>
  <div class="space-y-6">
    <PageHeader :title="invoice ? `Invoice ${truncateMiddle(invoice.id, 8, 6)}` : 'Invoice'">
      <template #breadcrumb>
        <RouterLink
          to="/admin/invoices"
          class="inline-flex items-center gap-1.5 text-xs text-muted transition-colors hover:text-text"
        >
          <ArrowLeft :size="13" aria-hidden="true" />
          Invoices
        </RouterLink>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary btn-sm" :disabled="refreshing" @click="load(true)">
          <RefreshCw :size="14" :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
          Refresh
        </button>
        <button v-if="invoice" type="button" class="btn-secondary btn-sm" @click="openSimulate">
          <FlaskConical :size="14" aria-hidden="true" />
          Simulate payment
        </button>
        <button v-if="canCancel" type="button" class="btn-danger btn-sm" @click="cancelOpen = true">
          <Ban :size="14" aria-hidden="true" />
          Cancel
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
      <Skeleton class="xl:col-span-2" height="h-64" rounded="rounded-2xl" />
      <Skeleton height="h-64" rounded="rounded-2xl" />
    </div>

    <EmptyState
      v-else-if="notFound || !invoice"
      title="Invoice not found"
      description="This invoice does not exist or was removed."
    >
      <RouterLink to="/admin/invoices" class="btn-secondary">Back to invoices</RouterLink>
    </EmptyState>

    <template v-else>
      <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-3">
        <!-- Summary -->
        <section class="card space-y-5 p-5 xl:col-span-2">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="text-xs uppercase tracking-wide text-muted">Amount</p>
              <div class="mt-1.5">
                <AmountDisplay :value="invoice.amount" :currency="invoice.currency" size="lg" />
              </div>
              <div class="mt-2.5 flex flex-wrap items-center gap-2">
                <StatusBadge :status="invoice.status" context="Invoice status" />
                <NetworkBadge :network="invoice.network" />
                <span v-if="invoice.type === 'token_purchase'" class="chip border-accent/30 bg-accent/10 text-accent">
                  Token purchase
                </span>
              </div>
            </div>
            <a
              v-if="paymentUrl"
              :href="paymentUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="btn-secondary btn-sm"
            >
              <Link2 :size="14" aria-hidden="true" />
              Open checkout
            </a>
          </div>

          <!-- Progress -->
          <div class="space-y-2.5 rounded-xl border border-border bg-surface-2/40 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2 text-xs">
              <span class="text-muted">Confirmed</span>
              <span class="flex items-baseline gap-1.5">
                <AmountDisplay :value="invoice.amount_confirmed" size="sm" />
                <span class="text-muted">/</span>
                <AmountDisplay :value="invoice.amount" :currency="invoice.currency" size="sm" muted />
              </span>
            </div>
            <div class="relative">
              <ProgressBar :value="receivedPercent" tone="warning" height="sm" label="Amount received" />
              <div class="mt-1.5">
                <ProgressBar
                  :value="confirmedPercent"
                  :tone="invoice.is_paid ? 'success' : 'primary'"
                  height="sm"
                  label="Amount confirmed"
                />
              </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted">
              <span>Detected {{ receivedPercent.toFixed(1) }}% · confirmed {{ confirmedPercent.toFixed(1) }}%</span>
              <span v-if="!isFullyCovered">
                Remaining <AmountDisplay :value="remaining" :currency="invoice.currency" size="sm" muted />
              </span>
            </div>
          </div>

          <!-- Meta grid -->
          <dl class="grid grid-cols-1 gap-x-6 gap-y-3.5 text-sm sm:grid-cols-2">
            <div class="min-w-0">
              <dt class="text-xs text-muted">Invoice ID</dt>
              <dd class="mt-0.5 flex items-center gap-1">
                <span class="mono truncate">{{ invoice.id }}</span>
                <CopyButton :value="invoice.id" label="Invoice ID" :size="12" />
              </dd>
            </div>
            <div class="min-w-0">
              <dt class="text-xs text-muted">External ID</dt>
              <dd class="mono mt-0.5 truncate">{{ invoice.external_id ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
              <dt class="text-xs text-muted">Merchant</dt>
              <dd class="mt-0.5 truncate">
                <RouterLink
                  v-if="invoice.merchant"
                  :to="{ name: 'merchant-detail', params: { id: invoice.merchant.id } }"
                  class="link"
                >
                  {{ invoice.merchant.name }}
                </RouterLink>
                <span v-else>—</span>
              </dd>
            </div>
            <div class="min-w-0">
              <dt class="text-xs text-muted">Customer</dt>
              <dd class="mt-0.5 truncate">
                {{ invoice.customer_email || invoice.customer_id || '—' }}
              </dd>
            </div>
            <div class="min-w-0">
              <dt class="text-xs text-muted">Created</dt>
              <dd class="mono mt-0.5">{{ formatDateTime(invoice.created_at) }}</dd>
            </div>
            <div class="min-w-0">
              <dt class="text-xs text-muted">Expires</dt>
              <dd class="mono mt-0.5">{{ formatDateTime(invoice.expires_at) }}</dd>
            </div>
            <div v-if="invoice.description" class="min-w-0 sm:col-span-2">
              <dt class="text-xs text-muted">Description</dt>
              <dd class="mt-0.5">{{ invoice.description }}</dd>
            </div>
            <div v-if="invoice.metadata && Object.keys(invoice.metadata).length" class="sm:col-span-2">
              <dt class="text-xs text-muted">Metadata</dt>
              <dd>
                <pre
                  class="mono mt-1 max-h-40 overflow-auto rounded-lg border border-border bg-bg/60 p-3 text-[12px] text-muted"
                >{{ JSON.stringify(invoice.metadata, null, 2) }}</pre>
              </dd>
            </div>
          </dl>
        </section>

        <!-- Payment address -->
        <section class="card flex flex-col items-center gap-4 self-start p-5">
          <h2 class="self-start text-sm font-semibold">Deposit address</h2>
          <QrCode :value="qrValue" :size="164" label="Deposit address QR code" />
          <div class="w-full">
            <div class="flex items-center gap-2 rounded-xl border border-border bg-bg/60 px-3 py-2.5">
              <code class="mono min-w-0 flex-1 break-all text-[12px]">{{ invoice.address }}</code>
              <CopyButton :value="invoice.address" label="Address" :size="14" notify />
            </div>
            <p class="mt-2 text-[11px] text-muted">
              {{ invoice.currency }} on {{ NETWORK_NAMES[invoice.network] ?? invoice.network }} only.
            </p>
          </div>
        </section>
      </div>

      <!-- Timeline -->
      <section class="card p-5">
        <h2 class="mb-4 text-sm font-semibold">Status timeline</h2>
        <ol>
          <li v-for="(step, index) in timeline" :key="step.key" class="flex gap-3.5 last:[&>div:last-child]:pb-0">
            <div class="flex flex-col items-center">
              <span
                class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2"
                :class="{
                  'border-success bg-success/25': step.state === 'done',
                  'border-warning bg-warning/25': step.state === 'current',
                  'border-danger bg-danger/25': step.state === 'failed',
                  'border-border bg-surface-2': step.state === 'todo',
                }"
                aria-hidden="true"
              >
                <span
                  v-if="step.state === 'current'"
                  class="h-1.5 w-1.5 animate-ping rounded-full bg-warning"
                />
              </span>
              <span
                v-if="index < timeline.length - 1"
                class="my-1 w-px flex-1 bg-border"
                aria-hidden="true"
              />
            </div>
            <div class="min-w-0 flex-1 pb-6">
              <p
                class="text-sm font-medium"
                :class="step.state === 'todo' ? 'text-muted' : 'text-text'"
              >
                {{ step.label }}
              </p>
              <p class="mono mt-0.5 text-[11px] text-muted">
                {{ step.at ? formatDateTime(step.at) : '—' }}
              </p>
            </div>
          </li>
        </ol>
      </section>

      <!-- Transactions -->
      <section class="card overflow-hidden">
        <div class="border-b border-border px-5 py-4">
          <h2 class="text-sm font-semibold">Transactions</h2>
        </div>
        <DataTable :columns="txColumns" :rows="transactions" caption="On-chain transactions">
          <template #cell-tx_hash="{ row }">
            <AddressDisplay :value="row.tx_hash" :href="row.explorer_url" label="Transaction hash" :head="12" :tail="8" />
          </template>
          <template #cell-amount="{ row }">
            <AmountDisplay :value="row.amount" :currency="invoice?.currency" size="sm" />
          </template>
          <template #cell-confirmations="{ row }">
            <span
              class="mono tabular-nums"
              :class="row.status === 'confirmed' ? 'text-success' : 'text-warning'"
            >
              {{ Math.min(row.confirmations, row.confirmations_required) }}/{{ row.confirmations_required }}
            </span>
          </template>
          <template #cell-status="{ row }">
            <StatusBadge :status="row.status" size="sm" context="Transaction status" />
          </template>
          <template #cell-created_at="{ row }">
            <span class="whitespace-nowrap text-xs text-muted">{{ formatRelative(row.created_at) }}</span>
          </template>
          <template #empty>
            <EmptyState
              :icon="ExternalLink"
              title="No transactions yet"
              description="Nothing has been detected on this address."
              compact
            />
          </template>
        </DataTable>
      </section>

      <!-- Webhooks -->
      <section class="card overflow-hidden">
        <div class="border-b border-border px-5 py-4">
          <h2 class="text-sm font-semibold">Webhook deliveries</h2>
        </div>
        <DataTable :columns="webhookColumns" :rows="webhookDeliveries" caption="Webhook deliveries">
          <template #cell-event="{ row }">
            <div class="min-w-0">
              <p class="mono truncate text-text">{{ row.event }}</p>
              <p class="truncate text-xs text-muted">{{ row.url }}</p>
            </div>
          </template>
          <template #cell-status="{ row }">
            <StatusBadge :status="row.status" size="sm" context="Delivery status" />
          </template>
          <template #cell-attempts="{ row }">
            <span class="mono tabular-nums text-muted">{{ row.attempts }}/{{ row.max_attempts }}</span>
          </template>
          <template #cell-response_code="{ row }">
            <span
              class="mono tabular-nums"
              :class="
                row.response_code && row.response_code >= 200 && row.response_code < 300
                  ? 'text-success'
                  : row.response_code
                    ? 'text-danger'
                    : 'text-muted'
              "
            >
              {{ row.response_code ?? '—' }}
            </span>
          </template>
          <template #cell-next_attempt_at="{ row }">
            <span class="whitespace-nowrap text-xs text-muted">
              {{ row.next_attempt_at ? formatRelative(row.next_attempt_at) : '—' }}
            </span>
          </template>
          <template #cell-actions="{ row }">
            <button
              type="button"
              class="btn-ghost btn-sm"
              :disabled="retryingWebhook === row.id"
              :aria-label="`Retry delivery ${row.event}`"
              @click="retryWebhook(row)"
            >
              <Spinner v-if="retryingWebhook === row.id" :size="13" />
              <RotateCw v-else :size="13" aria-hidden="true" />
              Retry
            </button>
          </template>
          <template #empty>
            <EmptyState
              :icon="Webhook"
              title="No deliveries"
              description="This merchant has no webhook URL, or no event has fired yet."
              compact
            />
          </template>
        </DataTable>
      </section>
    </template>

    <ConfirmDialog
      :open="cancelOpen"
      title="Cancel this invoice?"
      message="The checkout page will stop accepting payment. Funds sent after cancellation are still credited to the merchant balance."
      confirm-label="Cancel invoice"
      tone="danger"
      :loading="cancelling"
      @close="cancelOpen = false"
      @confirm="confirmCancel"
    />

    <Modal
      :open="simulateOpen"
      title="Simulate a payment"
      description="Creates a fake transaction through the real settlement pipeline. Requires SIMULATION_ENABLED on the backend."
      size="sm"
      @close="simulateOpen = false"
    >
      <div class="space-y-4">
        <div>
          <label for="sim-amount" class="label">Amount</label>
          <input
            id="sim-amount"
            v-model="simulateAmount"
            type="text"
            inputmode="decimal"
            class="input mono"
            data-autofocus
            :placeholder="invoice?.amount ?? '0'"
          />
          <p class="hint">Leave empty to pay the full invoice amount.</p>
        </div>
        <label class="flex cursor-pointer items-start gap-2.5">
          <input
            v-model="simulateConfirmed"
            type="checkbox"
            class="checkbox mt-0.5"
          />
          <span class="text-sm">
            Mark as confirmed
            <span class="mt-0.5 block text-xs text-muted">
              Unchecked creates a `detected` transaction that still needs confirmations.
            </span>
          </span>
        </label>
      </div>
      <template #footer>
        <button type="button" class="btn-secondary" @click="simulateOpen = false">Cancel</button>
        <button type="button" class="btn-primary" :disabled="simulating" @click="runSimulation">
          <Spinner v-if="simulating" :size="14" />
          Run simulation
        </button>
      </template>
    </Modal>
  </div>
</template>
