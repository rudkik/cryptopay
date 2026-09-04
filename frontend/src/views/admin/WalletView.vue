<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { KeyRound, RefreshCw, Save, ShieldAlert, Wallet, X } from 'lucide-vue-next'
import AddressDisplay from '@/components/AddressDisplay.vue'
import AmountDisplay from '@/components/AmountDisplay.vue'
import CodeBlock from '@/components/CodeBlock.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import Modal from '@/components/Modal.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import NetworkIcon from '@/components/NetworkIcon.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import SelectFilter from '@/components/SelectFilter.vue'
import Skeleton from '@/components/Skeleton.vue'
import Spinner from '@/components/Spinner.vue'
import type { Column } from '@/components/table'
import { walletsApi, type WalletUpdatePayload } from '@/api/wallets'
import type {
  DerivedAddressPreview,
  IssuedAddress,
  NetworkCode,
  PaginationMeta,
  WalletItem,
  WalletSource,
} from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { errorMessage, fieldErrors, reportError } from '@/composables/useErrorHandler'
import { formatRelative, trimAmount, truncateMiddle } from '@/utils/format'
import { NETWORK_CODES, NETWORK_OPTIONS } from '@/utils/options'
import { toast } from '@/utils/toast'

const auth = useAuthStore()

/* ------------------------------------------------------------------ wallets */

const wallets = ref<WalletItem[]>([])
const loading = ref(true)
const refreshing = ref(false)
const failed = ref(false)

/** `warning` only arrives on a PUT response, so it is kept per network until dismissed. */
const warnings = reactive<Record<string, string>>({})

const SOURCE_META: Record<WalletSource, { label: string; wrap: string; dot: string }> = {
  database: {
    label: 'Configured from database',
    wrap: 'border-success/25 bg-success-soft text-success',
    dot: 'bg-success',
  },
  env: {
    label: 'Configured from env',
    wrap: 'border-primary/25 bg-primary-soft text-primary-hover',
    dot: 'bg-primary',
  },
  none: {
    label: 'Not configured',
    wrap: 'border-danger/25 bg-danger-soft text-danger',
    dot: 'bg-danger',
  },
}

const KEYS_COMMAND = 'make keys'

const adminOnlyTitle = computed(() =>
  auth.isAdmin ? undefined : 'Only an admin can change the deposit wallet.',
)

function envVarFor(network: NetworkCode): string {
  return network === 'tron' ? 'TRON_XPUB' : 'EVM_XPUB'
}

/** Currency totals as `[currency, decimal string]`, keeping the API's string form. */
function receivedEntries(received: Record<string, string> | null | undefined): [string, string][] {
  return Object.entries(received ?? {})
}

function nonZeroReceived(received: Record<string, string> | null | undefined): [string, string][] {
  return receivedEntries(received).filter(([, amount]) => trimAmount(amount) !== '0')
}

function dismissWarning(network: string): void {
  delete warnings[network]
}

async function loadWallets(silent = false): Promise<void> {
  if (silent) refreshing.value = true
  else loading.value = true
  try {
    wallets.value = await walletsApi.list()
    failed.value = false
  } catch (error) {
    failed.value = true
    reportError(error, 'Failed to load the wallet configuration')
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

/* ------------------------------------------------------- set / change modal */

const editing = ref<WalletItem | null>(null)
const form = reactive({ xpub: '', label: '', applyToEvm: true })
const formErrors = ref<Record<string, string>>({})
const preview = ref<DerivedAddressPreview[]>([])
const previewing = ref(false)
const saving = ref(false)
const confirmChangeOpen = ref(false)

/** Ethereum and BSC derive from the same account key, so both can be set at once. */
const isEvm = computed(() => editing.value !== null && editing.value.network !== 'tron')

function openEditor(wallet: WalletItem): void {
  editing.value = wallet
  form.xpub = ''
  form.label = wallet.label ?? ''
  form.applyToEvm = wallet.network !== 'tron'
  formErrors.value = {}
  preview.value = []
  previewing.value = false
  confirmChangeOpen.value = false
}

function closeEditor(): void {
  if (saving.value) return
  editing.value = null
  confirmChangeOpen.value = false
  form.xpub = ''
  preview.value = []
  formErrors.value = {}
}

async function runPreview(): Promise<void> {
  const wallet = editing.value
  const xpub = form.xpub.trim()
  if (!wallet || !xpub || previewing.value) return
  previewing.value = true
  formErrors.value = {}
  try {
    preview.value = await walletsApi.preview(wallet.network, xpub)
  } catch (error) {
    preview.value = []
    const fields = fieldErrors(error)
    formErrors.value = {
      ...fields,
      xpub: fields.xpub ?? errorMessage(error, 'Could not derive addresses from this key'),
    }
  } finally {
    previewing.value = false
  }
}

/** Replacing a key that already issued addresses needs an explicit confirmation. */
function requestSave(): void {
  const wallet = editing.value
  if (!wallet || saving.value) return
  if (!form.xpub.trim()) {
    formErrors.value = { xpub: 'Enter an extended public key.' }
    return
  }
  if (wallet.addresses_issued > 0) {
    confirmChangeOpen.value = true
    return
  }
  void save()
}

const changeMessage = computed(() => {
  const wallet = editing.value
  if (!wallet) return ''
  return (
    `The ${wallet.addresses_issued} address(es) already issued on ${wallet.network_name} keep belonging to the current key ` +
    'and stay monitored, so funds already received are unaffected. ' +
    `Derivation continues from index ${wallet.next_index} instead of resetting, and every new invoice will use the new key.`
  )
})

async function save(): Promise<void> {
  const wallet = editing.value
  if (!wallet) return
  saving.value = true
  formErrors.value = {}
  try {
    const payload: WalletUpdatePayload = { xpub: form.xpub.trim() }
    const label = form.label.trim()
    if (label) payload.label = label
    if (wallet.network !== 'tron') payload.apply_to_evm = form.applyToEvm

    const updated = await walletsApi.update(wallet.network, payload)

    confirmChangeOpen.value = false
    editing.value = null
    form.xpub = ''
    preview.value = []

    if (updated.warning) {
      warnings[updated.network] = updated.warning
      toast.warning(`${updated.network_name} key replaced`, updated.warning)
    } else {
      delete warnings[updated.network]
      toast.success(`${updated.network_name} deposit key updated`)
    }
    await loadWallets(true)
  } catch (error) {
    confirmChangeOpen.value = false
    formErrors.value = fieldErrors(error)
    reportError(error, 'Could not save the extended public key')
  } finally {
    saving.value = false
  }
}

/** A stale preview belongs to a different key, so it is dropped on every edit. */
watch(
  () => form.xpub,
  () => {
    if (preview.value.length) preview.value = []
  },
)

/**
 * Both `Modal` instances own `document.body.style.overflow`; closing the nested
 * confirmation clears the scroll lock the editor modal underneath still needs.
 */
watch(confirmChangeOpen, async (open) => {
  if (open || editing.value === null) return
  await nextTick()
  document.body.style.overflow = 'hidden'
})

/* ------------------------------------------------------------ remove (env) */

const removing = ref<WalletItem | null>(null)
const removeBusy = ref(false)

const removeMessage = computed(() => {
  const wallet = removing.value
  if (!wallet) return ''
  return (
    `The stored key for ${wallet.network_name} is cleared and derivation falls back to the watcher's ` +
    `${envVarFor(wallet.network)} environment variable. If that key belongs to a different wallet, ` +
    'every address issued from now on — and the funds sent to it — will land in that wallet instead.'
  )
})

async function confirmRemove(): Promise<void> {
  const wallet = removing.value
  if (!wallet) return
  removeBusy.value = true
  try {
    const updated = await walletsApi.removeXpub(wallet.network)
    delete warnings[updated.network]
    removing.value = null
    toast.success(`${updated.network_name} now uses ${envVarFor(updated.network)}`)
    await loadWallets(true)
  } catch (error) {
    reportError(error, 'Could not remove the stored key')
  } finally {
    removeBusy.value = false
  }
}

/* -------------------------------------------------------- issued addresses */

const PER_PAGE = 25
const EMPTY_META: PaginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: PER_PAGE,
  total: 0,
}

const addressNetwork = ref<string>('')
const addressRows = ref<IssuedAddress[]>([])
const addressMeta = ref<PaginationMeta>({ ...EMPTY_META })
const addressPage = ref(1)
const addressLoading = ref(true)
const addressFailed = ref(false)

/**
 * Addresses live under a per-network endpoint, so "all networks" cannot be paged
 * server-side. It fetches the newest page from each network and shows the newest
 * `PER_PAGE` of the union — exact for what it claims, without faking pagination.
 */
const aggregated = computed(() => addressNetwork.value === '')

const addressColumns: Column[] = [
  { key: 'address', label: 'Address' },
  { key: 'derivation_index', label: 'Index', class: 'text-right', hideBelow: 'sm' },
  { key: 'network', label: 'Network', hideBelow: 'md' },
  { key: 'merchant', label: 'Merchant', hideBelow: 'lg' },
  { key: 'invoice_id', label: 'Invoice', hideBelow: 'lg' },
  { key: 'received', label: 'Received', class: 'text-right' },
  { key: 'created_at', label: 'Issued', class: 'text-right', hideBelow: 'md' },
]

function issuedAt(row: IssuedAddress): number {
  const time = row.created_at ? Date.parse(row.created_at) : Number.NaN
  return Number.isNaN(time) ? 0 : time
}

let addressRequestId = 0

async function loadAddresses(): Promise<void> {
  const current = ++addressRequestId
  addressLoading.value = true
  addressFailed.value = false
  try {
    if (aggregated.value) {
      const responses = await Promise.all(
        NETWORK_CODES.map((code) => walletsApi.addresses(code, { per_page: PER_PAGE })),
      )
      if (current !== addressRequestId) return
      const merged = responses
        .flatMap((response) => response.data ?? [])
        .sort((a, b) => issuedAt(b) - issuedAt(a))
      addressRows.value = merged.slice(0, PER_PAGE)
      addressMeta.value = {
        current_page: 1,
        last_page: 1,
        per_page: PER_PAGE,
        total: responses.reduce((sum, response) => sum + (response.meta?.total ?? 0), 0),
      }
      return
    }

    const response = await walletsApi.addresses(addressNetwork.value as NetworkCode, {
      page: addressPage.value,
      per_page: PER_PAGE,
    })
    if (current !== addressRequestId) return
    addressRows.value = response.data ?? []
    addressMeta.value = response.meta ?? {
      ...EMPTY_META,
      total: addressRows.value.length,
    }
  } catch (error) {
    if (current !== addressRequestId) return
    addressFailed.value = true
    addressRows.value = []
    addressMeta.value = { ...EMPTY_META }
    reportError(error, 'Failed to load issued addresses')
  } finally {
    if (current === addressRequestId) addressLoading.value = false
  }
}

function setAddressPage(page: number): void {
  addressPage.value = page
  void loadAddresses()
}

watch(addressNetwork, () => {
  addressPage.value = 1
  void loadAddresses()
})

/* ------------------------------------------------------------------ mount */

onMounted(async () => {
  await loadWallets()
  // Land on a network that actually derives addresses; the watcher triggers the load.
  const configured = wallets.value.find((wallet) => wallet.source !== 'none')
  const next = configured?.network ?? 'ethereum'
  if (addressNetwork.value === next) void loadAddresses()
  else addressNetwork.value = next
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Wallet"
      description="The extended public keys deposit addresses are derived from, and the addresses issued so far."
    >
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="refreshing" @click="loadWallets(true)">
          <RefreshCw :size="15" :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
          Refresh
        </button>
      </template>
    </PageHeader>

    <!-- Explainer -->
    <section class="card p-5">
      <div class="grid gap-5 lg:grid-cols-[1.5fr_1fr]">
        <div class="min-w-0 space-y-3">
          <h2 class="text-sm font-semibold">Where the money goes</h2>
          <ul class="space-y-2.5 text-sm text-muted">
            <li>
              Every invoice gets the next unused address derived from an account-level extended
              public key — <span class="mono text-text">m/44'/60'/0'/0/{index}</span> for Ethereum
              and BSC, which share one EVM key, and
              <span class="mono text-text">m/44'/195'/0'/0/{index}</span> for Tron.
            </li>
            <li>
              Funds stay on those addresses and belong to whoever holds the seed phrase — the wallet
              owner. This service stores only the public key, so it can watch for incoming transfers
              and can never move them.
            </li>
            <li>
              Changing a key leaves previously issued addresses with the old key; they stay
              monitored and the funds on them are unaffected. New addresses come from the new key
              and the derivation index continues instead of resetting.
            </li>
          </ul>
        </div>

        <div class="min-w-0 space-y-3">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-muted">Getting an xpub</p>
            <p class="mt-1.5 text-sm text-muted">
              Run this in the repo — it prints a fresh mnemonic plus
              <span class="mono text-text">EVM_XPUB</span> and
              <span class="mono text-text">TRON_XPUB</span>. Keep the mnemonic offline.
            </p>
          </div>
          <CodeBlock :code="KEYS_COMMAND" title="Terminal" />
          <p class="text-sm text-muted">
            Or export the account-level extended public key from an existing wallet — Ledger Live,
            Trust Wallet or Electrum — at the path shown on each card below.
          </p>
          <p
            class="flex items-start gap-2 rounded-xl border border-danger/25 bg-danger-soft px-3 py-2.5 text-xs text-danger"
          >
            <ShieldAlert :size="15" class="mt-px shrink-0" aria-hidden="true" />
            <span>
              Never paste a private key (<span class="mono">xprv</span>) or a seed phrase here. Only
              the extended public key.
            </span>
          </p>
        </div>
      </div>
    </section>

    <!-- Network cards -->
    <div v-if="loading" class="grid gap-4 xl:grid-cols-2">
      <Skeleton v-for="i in 3" :key="i" height="h-80" rounded="rounded-2xl" />
    </div>

    <EmptyState
      v-else-if="wallets.length === 0"
      :icon="Wallet"
      :title="failed ? 'Wallet configuration unavailable' : 'No networks to configure'"
      :description="
        failed
          ? 'The wallet endpoint could not be reached. Try again in a moment.'
          : 'Seed the networks table on the backend to configure deposit keys.'
      "
    >
      <button type="button" class="btn-secondary" @click="loadWallets(true)">Try again</button>
    </EmptyState>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <section v-for="wallet in wallets" :key="wallet.network" class="card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
          <div class="flex min-w-0 items-center gap-3">
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border bg-surface"
            >
              <NetworkIcon :network="wallet.network" :size="32" />
            </span>
            <div class="min-w-0">
              <h2 class="truncate text-sm font-semibold">{{ wallet.network_name }}</h2>
              <p class="truncate text-xs text-muted">{{ wallet.standard ?? wallet.network }}</p>
            </div>
          </div>
          <span
            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1 text-xs font-medium"
            :class="SOURCE_META[wallet.source].wrap"
          >
            <span
              class="h-1.5 w-1.5 shrink-0 rounded-full"
              :class="SOURCE_META[wallet.source].dot"
              aria-hidden="true"
            />
            {{ SOURCE_META[wallet.source].label }}
          </span>
        </header>

        <div class="space-y-4 px-5 py-5">
          <p
            v-if="wallet.source === 'none'"
            class="flex items-start gap-2 rounded-xl border border-danger/25 bg-danger-soft px-3 py-2.5 text-xs text-danger"
          >
            <ShieldAlert :size="15" class="mt-px shrink-0" aria-hidden="true" />
            <span>No key set — this network cannot issue deposit addresses.</span>
          </p>

          <div
            v-if="warnings[wallet.network]"
            class="flex items-start gap-2 rounded-xl border border-warning/25 bg-warning-soft px-3 py-2.5 text-xs text-warning"
          >
            <ShieldAlert :size="15" class="mt-px shrink-0" aria-hidden="true" />
            <p class="min-w-0 flex-1">{{ warnings[wallet.network] }}</p>
            <button
              type="button"
              class="-mr-1 -mt-1 shrink-0 rounded-md p-1 transition-colors hover:bg-warning/15"
              aria-label="Dismiss warning"
              @click="dismissWarning(wallet.network)"
            >
              <X :size="13" aria-hidden="true" />
            </button>
          </div>

          <dl class="space-y-3 text-xs">
            <div class="min-w-0">
              <dt class="text-muted">Extended public key</dt>
              <dd class="mt-0.5 min-w-0">
                <span v-if="wallet.xpub_masked" class="mono break-all text-text">
                  {{ wallet.xpub_masked }}
                </span>
                <span v-else-if="wallet.source === 'env'" class="text-muted">
                  — using the watcher's <span class="mono text-text">{{ envVarFor(wallet.network) }}</span>
                </span>
                <span v-else class="text-muted">Not set</span>
              </dd>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div class="min-w-0">
                <dt class="text-muted">Derivation path</dt>
                <dd class="mono mt-0.5 truncate text-text">{{ wallet.derivation_path }}</dd>
              </div>
              <div class="min-w-0 text-right">
                <dt class="text-muted">Label</dt>
                <dd class="mt-0.5 truncate text-text">{{ wallet.label || '—' }}</dd>
              </div>
              <div class="min-w-0">
                <dt class="text-muted">Addresses issued</dt>
                <dd class="mono mt-0.5 truncate text-text">
                  {{ wallet.addresses_issued }}
                  <span class="text-muted">· next index {{ wallet.next_index }}</span>
                </dd>
              </div>
              <div class="min-w-0 text-right">
                <dt class="text-muted">Set by</dt>
                <dd class="mt-0.5 truncate text-text">
                  <template v-if="wallet.xpub_set_by || wallet.xpub_set_at">
                    {{ wallet.xpub_set_by?.name ?? 'Unknown' }}
                    <span class="text-muted">· {{ formatRelative(wallet.xpub_set_at) }}</span>
                  </template>
                  <template v-else>—</template>
                </dd>
              </div>
            </div>

            <div class="min-w-0">
              <dt class="text-muted">Last address</dt>
              <dd class="mt-0.5 min-w-0">
                <span v-if="wallet.last_address" class="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <AddressDisplay
                    :value="wallet.last_address.address"
                    :href="wallet.last_address.explorer_url"
                    label="Deposit address"
                    :head="10"
                    :tail="8"
                    size="sm"
                  />
                  <span class="mono text-muted">#{{ wallet.last_address.derivation_index }}</span>
                </span>
                <span v-else class="text-muted">None issued yet</span>
              </dd>
            </div>

            <div class="min-w-0">
              <dt class="text-muted">Received</dt>
              <dd class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                <AmountDisplay
                  v-for="[currency, amount] in receivedEntries(wallet.received)"
                  :key="currency"
                  :value="amount"
                  :currency="currency"
                  size="sm"
                  logo
                />
                <span v-if="receivedEntries(wallet.received).length === 0" class="text-muted">—</span>
              </dd>
            </div>
          </dl>

          <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4" :title="adminOnlyTitle">
            <button
              type="button"
              class="btn-primary btn-sm"
              :disabled="!auth.isAdmin"
              @click="openEditor(wallet)"
            >
              <KeyRound :size="14" aria-hidden="true" />
              {{ wallet.source === 'database' ? 'Change xpub' : 'Set xpub' }}
            </button>
            <button
              v-if="wallet.source === 'database'"
              type="button"
              class="btn-danger btn-sm"
              :disabled="!auth.isAdmin"
              @click="removing = wallet"
            >
              Remove (use env)
            </button>
            <p v-if="!auth.isAdmin" class="text-xs text-muted">Read-only — admins can edit.</p>
          </div>
        </div>
      </section>
    </div>

    <!-- Issued addresses -->
    <section class="card overflow-hidden">
      <div
        class="flex flex-col gap-3 border-b border-border px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="min-w-0">
          <h2 class="text-sm font-semibold">Issued addresses</h2>
          <p class="mt-0.5 text-xs text-muted">Deposit addresses derived for invoices, newest first.</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <SelectFilter
            v-model="addressNetwork"
            label="Network"
            placeholder="All networks"
            :options="NETWORK_OPTIONS"
          />
          <button
            type="button"
            class="btn-ghost btn-sm"
            :disabled="addressLoading"
            @click="loadAddresses()"
          >
            <RefreshCw :size="14" :class="addressLoading ? 'animate-spin' : ''" aria-hidden="true" />
            Refresh
          </button>
        </div>
      </div>

      <DataTable
        :columns="addressColumns"
        :rows="addressRows"
        :loading="addressLoading"
        caption="Issued deposit addresses"
      >
        <template #cell-address="{ row }">
          <AddressDisplay
            :value="row.address"
            :href="row.explorer_url"
            label="Deposit address"
            :head="10"
            :tail="8"
          />
        </template>
        <template #cell-derivation_index="{ row }">
          <span class="mono tabular-nums text-muted">{{ row.derivation_index }}</span>
        </template>
        <template #cell-network="{ row }">
          <NetworkBadge :network="row.network" size="sm" compact />
        </template>
        <template #cell-merchant="{ row }">
          <span class="truncate text-muted">{{ row.merchant?.name ?? '—' }}</span>
        </template>
        <template #cell-invoice_id="{ row }">
          <RouterLink
            v-if="row.invoice_id"
            :to="`/admin/invoices/${row.invoice_id}`"
            class="link mono text-xs"
          >
            {{ truncateMiddle(row.invoice_id, 6, 6) }}
          </RouterLink>
          <span v-else class="text-muted">—</span>
        </template>
        <template #cell-received="{ row }">
          <span class="inline-flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
            <AmountDisplay
              v-for="[currency, amount] in nonZeroReceived(row.received)"
              :key="currency"
              :value="amount"
              :currency="currency"
              size="sm"
              logo
            />
            <span v-if="nonZeroReceived(row.received).length === 0" class="text-muted">—</span>
          </span>
        </template>
        <template #cell-created_at="{ row }">
          <span class="whitespace-nowrap text-xs text-muted">{{ formatRelative(row.created_at) }}</span>
        </template>
        <template #empty>
          <EmptyState
            :icon="Wallet"
            :title="addressFailed ? 'Could not load addresses' : 'No addresses issued yet'"
            :description="
              addressFailed
                ? 'The request failed. Check the API and try again.'
                : 'Addresses appear here as soon as invoices start deriving them.'
            "
            compact
          >
            <button v-if="addressFailed" type="button" class="btn-secondary" @click="loadAddresses()">
              Try again
            </button>
          </EmptyState>
        </template>
      </DataTable>

      <Pagination
        v-if="!aggregated"
        :meta="addressMeta"
        :disabled="addressLoading"
        @change="setAddressPage"
      />
      <p
        v-else-if="addressRows.length > 0"
        class="border-t border-border px-4 py-3 text-xs text-muted"
      >
        Showing the {{ addressRows.length }} most recent of
        <span class="font-medium text-text">{{ addressMeta.total }}</span> addresses across all
        networks. Pick a network to page through every address.
      </p>
    </section>

    <!-- Set / change xpub -->
    <Modal
      :open="editing !== null"
      size="lg"
      :title="editing?.source === 'database' ? 'Change extended public key' : 'Set extended public key'"
      :description="
        editing
          ? `${editing.network_name} · addresses are derived at ${editing.derivation_path}/{index}`
          : undefined
      "
      @close="closeEditor"
    >
      <form v-if="editing" class="space-y-5" novalidate @submit.prevent="requestSave">
        <div>
          <label for="wallet-xpub" class="label">Extended public key (xpub)</label>
          <textarea
            id="wallet-xpub"
            v-model="form.xpub"
            rows="3"
            class="input mono resize-y break-all text-xs"
            :class="formErrors.xpub ? 'input-error' : ''"
            spellcheck="false"
            autocomplete="off"
            autocapitalize="off"
            autocorrect="off"
            placeholder="xpub…"
            data-autofocus
            :aria-invalid="formErrors.xpub ? 'true' : undefined"
            aria-describedby="wallet-xpub-hint"
          />
          <p v-if="formErrors.xpub" class="error-text">{{ formErrors.xpub }}</p>
          <p id="wallet-xpub-hint" class="hint">
            Public key only. Never an <span class="mono">xprv</span> or a seed phrase.
          </p>
        </div>

        <div>
          <label for="wallet-label" class="label">Label (optional)</label>
          <input
            id="wallet-label"
            v-model="form.label"
            type="text"
            class="input"
            :class="formErrors.label ? 'input-error' : ''"
            placeholder="Ledger Nano — main"
            autocomplete="off"
          />
          <p v-if="formErrors.label" class="error-text">{{ formErrors.label }}</p>
        </div>

        <label v-if="isEvm" class="flex cursor-pointer items-start gap-2.5">
          <input
            id="wallet-apply-evm"
            v-model="form.applyToEvm"
            type="checkbox"
            class="checkbox mt-0.5"
          />
          <span class="min-w-0">
            <span class="block text-sm">Apply to both EVM networks (Ethereum + BSC)</span>
            <span class="block text-xs text-muted">They derive from the same account key.</span>
          </span>
        </label>

        <div class="space-y-3 border-t border-border pt-4">
          <button
            type="button"
            class="btn-secondary btn-sm"
            :disabled="previewing || !form.xpub.trim()"
            @click="runPreview"
          >
            <Spinner v-if="previewing" :size="13" />
            Preview addresses
          </button>

          <div v-if="preview.length" class="rounded-xl border border-border bg-surface-2 p-3.5">
            <p class="text-xs text-muted">
              Compare these with the first addresses in your own wallet app before saving.
            </p>
            <ul class="mt-3 space-y-2.5">
              <li
                v-for="row in preview"
                :key="row.index"
                class="flex flex-wrap items-center gap-x-3 gap-y-1"
              >
                <span class="mono w-6 shrink-0 text-xs text-muted">#{{ row.index }}</span>
                <span class="mono shrink-0 text-[11px] text-muted">{{ row.path }}</span>
                <AddressDisplay
                  :value="row.address"
                  label="Derived address"
                  :head="10"
                  :tail="8"
                  size="sm"
                />
              </li>
            </ul>
          </div>
        </div>
      </form>

      <template #footer>
        <button type="button" class="btn-secondary" :disabled="saving" @click="closeEditor">
          Cancel
        </button>
        <button
          type="button"
          class="btn-primary"
          :disabled="saving || !form.xpub.trim()"
          @click="requestSave"
        >
          <Spinner v-if="saving" :size="14" />
          <Save v-else :size="15" aria-hidden="true" />
          Save
        </button>
      </template>
    </Modal>

    <ConfirmDialog
      :open="confirmChangeOpen"
      title="Replace the deposit key?"
      :message="changeMessage"
      confirm-label="Replace key"
      tone="danger"
      :loading="saving"
      @close="confirmChangeOpen = false"
      @confirm="save"
    />

    <ConfirmDialog
      :open="removing !== null"
      title="Remove the stored key?"
      :message="removeMessage"
      confirm-label="Remove and use env"
      tone="danger"
      :loading="removeBusy"
      @close="removing = null"
      @confirm="confirmRemove"
    />
  </div>
</template>
