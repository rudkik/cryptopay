<script setup lang="ts">
import { computed, onMounted, ref, toRef, watch } from 'vue'
import {
  AlertTriangle,
  ArrowUpRight,
  Ban,
  Clock,
  ExternalLink,
  Loader2,
  RefreshCw,
  ShieldCheck,
  TimerOff,
  Wallet,
} from 'lucide-vue-next'
import AppLogo from '@/components/AppLogo.vue'
import AmountDisplay from '@/components/AmountDisplay.vue'
import CopyButton from '@/components/CopyButton.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import ProgressBar from '@/components/ProgressBar.vue'
import QrCode from '@/components/QrCode.vue'
import Skeleton from '@/components/Skeleton.vue'
import SuccessCheck from '@/components/SuccessCheck.vue'
import { publicInvoicesApi } from '@/api/invoices'
import { isApiError } from '@/api/http'
import type { PublicInvoice } from '@/api/types'
import { useCountdown } from '@/composables/useCountdown'
import { usePolling } from '@/composables/usePolling'
import {
  compareAmounts,
  formatDateTime,
  percentOf,
  subtractAmounts,
  truncateMiddle,
} from '@/utils/format'
import { safeUrl } from '@/utils/url'

const props = defineProps<{ id: string }>()

const invoice = ref<PublicInvoice | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const refreshing = ref(false)

const expiresAt = computed(() => invoice.value?.expires_at ?? null)
const countdown = useCountdown(toRef(expiresAt))

/** Terminal states stop the 5s poll. */
const isSettled = computed(() =>
  ['paid', 'overpaid', 'cancelled', 'expired', 'partially_paid'].includes(invoice.value?.status ?? ''),
)

const isPaid = computed(() => invoice.value?.status === 'paid' || invoice.value?.status === 'overpaid')
const isAwaiting = computed(() => invoice.value?.status === 'pending' || invoice.value?.status === 'confirming')

/** Exact remainder, computed on the decimal strings (never via floats). */
const remaining = computed(() =>
  invoice.value ? subtractAmounts(invoice.value.amount, invoice.value.amount_received, true) : '0',
)
const hasRemaining = computed(() => compareAmounts(remaining.value, '0') > 0)

const hasPartialPayment = computed(
  () => invoice.value !== null && compareAmounts(invoice.value.amount_received, '0') > 0,
)

const receivedPercent = computed(() =>
  invoice.value ? percentOf(invoice.value.amount_received, invoice.value.amount) : 0,
)
const confirmedPercent = computed(() =>
  invoice.value ? percentOf(invoice.value.amount_confirmed, invoice.value.amount) : 0,
)

const pendingTransactions = computed(
  () => invoice.value?.transactions?.filter((tx) => tx.status !== 'orphaned') ?? [],
)

/**
 * `success_url` / `cancel_url` are merchant-supplied and rendered on a page open
 * to untrusted end users, so they are accepted only as plain http(s) links —
 * a `javascript:` value would otherwise run script on the checkout. Unsafe
 * values simply hide the button; navigation always requires a user click
 * (there is no auto-redirect anywhere on this page).
 */
const successUrl = computed(() => safeUrl(invoice.value?.success_url))
const cancelUrl = computed(() => safeUrl(invoice.value?.cancel_url))
const explorerAddressUrl = computed(() => safeUrl(invoice.value?.explorer_address_url))

/** Explorer links per transaction, sanitised the same way. */
function txExplorerUrl(url: string | null | undefined): string | null {
  return safeUrl(url)
}

/**
 * The QR must encode the very address shown and copied below it — two payment
 * destinations on one page is a payment-redirection bug waiting to happen.
 * `qr_payload` is the bare address (Tron) or an EIP-681 URI embedding it (EVM),
 * so it has to contain `invoice.address` verbatim; if it ever does not, we fall
 * back to encoding the displayed address and drop the payload.
 */
const qrValue = computed(() => {
  const current = invoice.value
  if (!current?.address) return ''
  const payload = current.qr_payload ?? ''
  return payload.includes(current.address) ? payload : current.address
})

async function load(silent = false): Promise<void> {
  if (!silent) loading.value = true
  refreshing.value = silent
  try {
    invoice.value = await publicInvoicesApi.get(props.id)
    loadError.value = null
  } catch (error) {
    if (isApiError(error) && error.status === 404) {
      loadError.value = 'This payment link does not exist or has been removed.'
    } else if (!silent) {
      loadError.value = isApiError(error) ? error.message : 'Unable to load this payment.'
    }
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

const poller = usePolling(() => load(true), 5000)

watch(isSettled, (settled) => {
  if (settled) poller.stop()
})

// A hard expiry while the tab is open: refresh once so the backend state catches up.
watch(countdown.expired, (expired) => {
  if (expired && isAwaiting.value) void load(true)
})

onMounted(async () => {
  await load()
  if (!isSettled.value && !loadError.value) poller.start()
})
</script>

<template>
  <div class="glow-bg relative min-h-screen overflow-hidden px-4 py-8 sm:py-12">
    <div class="grid-lines pointer-events-none absolute inset-0 opacity-30" aria-hidden="true" />

    <div class="relative mx-auto w-full max-w-[440px]">
      <div class="mb-6 flex items-center justify-center gap-2.5">
        <AppLogo :size="26" />
        <span class="text-sm font-semibold tracking-tight">CryptoPay</span>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="card-glass space-y-5 p-6">
        <Skeleton width="w-28" height="h-3" />
        <Skeleton width="w-44" height="h-9" />
        <Skeleton width="w-full" height="h-56" rounded="rounded-2xl" />
        <Skeleton :lines="3" height="h-3.5" />
      </div>

      <!-- Hard failure -->
      <div v-else-if="loadError || !invoice" class="card-glass p-8 text-center">
        <div
          class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border border-danger/30 bg-danger/10 text-danger"
        >
          <AlertTriangle :size="20" aria-hidden="true" />
        </div>
        <h1 class="mt-4 text-base font-semibold">Payment unavailable</h1>
        <p class="mt-1.5 text-sm text-muted">{{ loadError ?? 'Unable to load this payment.' }}</p>
        <button type="button" class="btn-secondary mt-5" @click="load()">
          <RefreshCw :size="14" aria-hidden="true" />
          Try again
        </button>
      </div>

      <template v-else>
        <!-- PAID / OVERPAID -->
        <div v-if="isPaid" class="card-glass overflow-hidden">
          <div class="flex flex-col items-center px-6 pb-6 pt-8 text-center">
            <SuccessCheck :size="76" />
            <h1 class="mt-5 text-lg font-semibold">
              {{ invoice.status === 'overpaid' ? 'Payment received (overpaid)' : 'Payment confirmed' }}
            </h1>
            <p class="mt-1.5 text-sm text-muted">
              {{
                invoice.status === 'overpaid'
                  ? 'We received more than the invoiced amount. Contact the merchant about the difference.'
                  : 'Your transaction has been confirmed on-chain.'
              }}
            </p>

            <div class="mt-6 w-full rounded-2xl border border-border bg-bg/40 p-4">
              <div class="flex items-baseline justify-between gap-3">
                <span class="text-xs uppercase tracking-wide text-muted">Paid</span>
                <AmountDisplay
                  :value="invoice.amount_confirmed"
                  :currency="invoice.currency"
                  size="lg"
                />
              </div>
              <div class="mt-2.5 flex items-center justify-between gap-3 text-xs text-muted">
                <span>Network</span>
                <NetworkBadge :network="invoice.network" :name="invoice.network_name" size="sm" />
              </div>
              <div v-if="invoice.paid_at" class="mt-2.5 flex items-center justify-between gap-3 text-xs text-muted">
                <span>Confirmed at</span>
                <span class="mono">{{ formatDateTime(invoice.paid_at) }}</span>
              </div>
            </div>

            <a
              v-if="successUrl"
              :href="successUrl"
              class="btn-primary mt-5 w-full"
              rel="noopener noreferrer"
            >
              Continue
              <ArrowUpRight :size="15" aria-hidden="true" />
            </a>
          </div>

          <div v-if="pendingTransactions.length" class="border-t border-border px-6 py-4">
            <p class="mb-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted">
              Transactions
            </p>
            <ul class="space-y-2">
              <li
                v-for="tx in pendingTransactions"
                :key="tx.tx_hash"
                class="flex items-center justify-between gap-3 text-xs"
              >
                <a
                  v-if="txExplorerUrl(tx.explorer_url)"
                  :href="txExplorerUrl(tx.explorer_url)!"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="mono inline-flex items-center gap-1 text-muted transition-colors hover:text-primary-hover"
                >
                  {{ truncateMiddle(tx.tx_hash, 10, 8) }}
                  <ExternalLink :size="11" aria-hidden="true" />
                </a>
                <span v-else class="mono text-muted">{{ truncateMiddle(tx.tx_hash, 10, 8) }}</span>
                <AmountDisplay :value="tx.amount" :currency="invoice.currency" size="sm" />
              </li>
            </ul>
          </div>
        </div>

        <!-- CANCELLED -->
        <div v-else-if="invoice.status === 'cancelled'" class="card-glass p-8 text-center">
          <div
            class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border border-danger/30 bg-danger/10 text-danger"
          >
            <Ban :size="20" aria-hidden="true" />
          </div>
          <h1 class="mt-4 text-base font-semibold">Payment cancelled</h1>
          <p class="mt-1.5 text-sm text-muted">
            This invoice was cancelled by the merchant. Do not send any funds.
          </p>
          <a
            v-if="cancelUrl"
            :href="cancelUrl"
            class="btn-secondary mt-5"
            rel="noopener noreferrer"
          >
            Return to merchant
            <ArrowUpRight :size="14" aria-hidden="true" />
          </a>
        </div>

        <!-- EXPIRED -->
        <div v-else-if="invoice.status === 'expired'" class="card-glass p-8 text-center">
          <div
            class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border border-danger/30 bg-danger/10 text-danger"
          >
            <TimerOff :size="20" aria-hidden="true" />
          </div>
          <h1 class="mt-4 text-base font-semibold">Payment window closed</h1>
          <p class="mt-1.5 text-sm text-muted">
            This invoice expired on {{ formatDateTime(invoice.expires_at) }}. Start a new payment with
            the merchant.
          </p>
          <p class="mt-4 rounded-xl border border-warning/25 bg-warning/10 px-3.5 py-2.5 text-xs text-warning">
            Funds sent to this address after expiry are still credited to the merchant, but the order is
            no longer tracked here.
          </p>
          <a
            v-if="cancelUrl"
            :href="cancelUrl"
            class="btn-secondary mt-5"
            rel="noopener noreferrer"
          >
            Return to merchant
            <ArrowUpRight :size="14" aria-hidden="true" />
          </a>
        </div>

        <!-- PARTIALLY PAID -->
        <div v-else-if="invoice.status === 'partially_paid'" class="card-glass p-6 sm:p-7">
          <div class="flex flex-col items-center text-center">
            <SuccessCheck :size="68" tone="warning" />
            <h1 class="mt-4 text-base font-semibold">Partial payment received</h1>
            <p class="mt-1.5 text-sm text-muted">
              We received less than the invoiced amount. Contact the merchant to resolve this order.
            </p>
          </div>

          <div class="mt-6 space-y-3 rounded-2xl border border-border bg-bg/40 p-4">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-xs uppercase tracking-wide text-muted">Received</span>
              <AmountDisplay :value="invoice.amount_confirmed" :currency="invoice.currency" size="lg" />
            </div>
            <ProgressBar :value="confirmedPercent" tone="warning" label="Amount received" />
            <div class="flex items-baseline justify-between gap-3 text-xs text-muted">
              <span>Invoiced</span>
              <AmountDisplay :value="invoice.amount" :currency="invoice.currency" size="sm" muted />
            </div>
          </div>
        </div>

        <!-- AWAITING PAYMENT (pending / confirming) -->
        <div v-else class="card-glass overflow-hidden">
          <div class="border-b border-border px-6 py-5 text-center">
            <p class="text-xs font-medium uppercase tracking-wide text-muted">Amount due</p>
            <div class="mt-2">
              <AmountDisplay :value="invoice.amount" :currency="invoice.currency" size="xl" />
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
              <NetworkBadge :network="invoice.network" :name="invoice.network_name" />
              <span
                v-if="invoice.status === 'confirming'"
                class="chip border-warning/30 bg-warning/10 text-warning"
              >
                <Loader2 :size="11" class="animate-spin" aria-hidden="true" />
                Confirming
              </span>
              <span v-else class="chip">
                <Wallet :size="11" aria-hidden="true" />
                Awaiting payment
              </span>
            </div>
            <p v-if="invoice.description" class="mt-3 text-sm text-muted">{{ invoice.description }}</p>
          </div>

          <div class="flex flex-col items-center gap-5 px-6 py-6">
            <QrCode
              :value="qrValue"
              :size="192"
              :label="`QR code for ${invoice.amount} ${invoice.currency} on ${invoice.network_name}`"
            />

            <div class="w-full">
              <p class="label mb-1.5">Send to this address</p>
              <div
                class="flex items-center gap-2 rounded-xl border border-border bg-bg/60 px-3 py-2.5"
              >
                <code class="mono min-w-0 flex-1 break-all text-[12.5px] text-text">
                  {{ invoice.address }}
                </code>
                <CopyButton :value="invoice.address" label="Address" :size="15" notify />
              </div>
              <a
                v-if="explorerAddressUrl"
                :href="explorerAddressUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-2 inline-flex items-center gap-1 text-xs text-muted transition-colors hover:text-primary-hover"
              >
                View address on explorer
                <ExternalLink :size="11" aria-hidden="true" />
              </a>
            </div>

            <p
              class="flex w-full items-start gap-2.5 rounded-xl border border-warning/25 bg-warning/[.07] px-3.5 py-3 text-xs leading-relaxed text-warning"
            >
              <AlertTriangle :size="15" class="mt-px shrink-0" aria-hidden="true" />
              <span>
                Send only <strong class="font-semibold">{{ invoice.currency }}</strong> on
                <strong class="font-semibold">{{ invoice.network_name }}</strong>. Any other asset or
                network will be lost permanently.
              </span>
            </p>

            <!-- Countdown -->
            <div
              v-if="invoice.expires_at"
              class="flex w-full items-center justify-between gap-3 rounded-xl border border-border bg-bg/40 px-3.5 py-2.5"
            >
              <span class="inline-flex items-center gap-2 text-xs text-muted">
                <Clock :size="14" aria-hidden="true" />
                Expires in
              </span>
              <span
                class="mono text-sm font-medium tabular-nums"
                :class="countdown.urgent.value ? 'text-warning' : 'text-text'"
                role="timer"
                :aria-label="`Time remaining ${countdown.label.value}`"
              >
                {{ countdown.label.value }}
              </span>
            </div>

            <!-- Confirmation progress -->
            <div v-if="hasPartialPayment" class="w-full space-y-3 rounded-xl border border-border bg-bg/40 p-4">
              <div class="flex items-baseline justify-between gap-3">
                <span class="text-xs uppercase tracking-wide text-muted">Received</span>
                <AmountDisplay
                  :value="invoice.amount_received"
                  :currency="invoice.currency"
                  size="sm"
                />
              </div>
              <ProgressBar :value="receivedPercent" tone="warning" animated label="Amount received" />
              <p v-if="hasRemaining" class="text-xs text-muted">
                <AmountDisplay :value="remaining" :currency="invoice.currency" size="sm" muted />
                still required.
              </p>

              <ul v-if="pendingTransactions.length" class="space-y-2.5 border-t border-border pt-3">
                <li v-for="tx in pendingTransactions" :key="tx.tx_hash" class="space-y-1.5">
                  <div class="flex items-center justify-between gap-3 text-xs">
                    <a
                      v-if="txExplorerUrl(tx.explorer_url)"
                      :href="txExplorerUrl(tx.explorer_url)!"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="mono inline-flex items-center gap-1 text-muted transition-colors hover:text-primary-hover"
                    >
                      {{ truncateMiddle(tx.tx_hash, 8, 6) }}
                      <ExternalLink :size="11" aria-hidden="true" />
                    </a>
                    <span v-else class="mono text-muted">{{ truncateMiddle(tx.tx_hash, 8, 6) }}</span>
                    <span
                      class="mono tabular-nums"
                      :class="tx.status === 'confirmed' ? 'text-success' : 'text-warning'"
                    >
                      {{ Math.min(tx.confirmations, tx.confirmations_required) }}/{{
                        tx.confirmations_required
                      }}
                      <span class="sr-only">confirmations</span>
                    </span>
                  </div>
                  <ProgressBar
                    :value="percentOf(String(tx.confirmations), String(tx.confirmations_required))"
                    :tone="tx.status === 'confirmed' ? 'success' : 'warning'"
                    height="sm"
                    :label="`Confirmations for ${tx.tx_hash}`"
                  />
                </li>
              </ul>
            </div>
          </div>

          <div
            class="flex items-center justify-between gap-3 border-t border-border px-6 py-3.5 text-[11px] text-muted"
          >
            <span class="inline-flex items-center gap-1.5">
              <ShieldCheck :size="13" aria-hidden="true" />
              Secured by CryptoPay
            </span>
            <span class="inline-flex items-center gap-1.5">
              <RefreshCw
                :size="12"
                :class="refreshing ? 'animate-spin' : ''"
                aria-hidden="true"
              />
              Live · updates every 5s
            </span>
          </div>
        </div>

        <p class="mt-4 text-center text-[11px] text-muted">
          Invoice <span class="mono">{{ truncateMiddle(invoice.id, 8, 8) }}</span>
        </p>
      </template>
    </div>
  </div>
</template>
