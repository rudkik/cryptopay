<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { Blocks, RefreshCw, Save, ShieldAlert } from 'lucide-vue-next'
import EmptyState from '@/components/EmptyState.vue'
import HealthDot from '@/components/HealthDot.vue'
import CoinLogo from '@/components/CoinLogo.vue'
import NetworkIcon from '@/components/NetworkIcon.vue'
import PageHeader from '@/components/PageHeader.vue'
import Skeleton from '@/components/Skeleton.vue'
import Spinner from '@/components/Spinner.vue'
import { networksApi } from '@/api/networks'
import { walletsApi } from '@/api/wallets'
import type { Currency, Network, NetworkCode, TokenContract, WalletItem } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useNetworksStore } from '@/stores/networks'
import { fieldErrors, reportError } from '@/composables/useErrorHandler'
import { formatRelative } from '@/utils/format'
import { toast } from '@/utils/toast'

const auth = useAuthStore()
const store = useNetworksStore()

/**
 * Chain configuration is admin-only server-side (every write answers 403 for a
 * viewer). Reflect that in the UI instead of letting a read-only operator fill
 * in thirty fields and lose the lot to a toast.
 */
const adminOnlyTitle = computed(() =>
  auth.isAdmin ? undefined : 'Only an admin can change network settings.',
)

const loading = ref(true)
const refreshing = ref(false)

/** Per-network draft state so edits are explicit and cancellable. */
interface NetworkDraft {
  confirmations_required: number
  is_enabled: boolean
  explorer_tx_url: string
  explorer_address_url: string
}

const drafts = reactive<Record<string, NetworkDraft>>({})
const tokenDrafts = reactive<Record<string, { contract_address: string; decimals: number; is_enabled: boolean }>>({})
const savingNetwork = ref<string | null>(null)
const savingToken = ref<string | null>(null)
const errors = ref<Record<string, Record<string, string>>>({})

const networks = computed(() => store.items)

/**
 * Deposit-wallet status per network code. Purely advisory: when the call fails
 * the map stays empty and no banner is rendered.
 */
const wallets = ref<Record<string, WalletItem>>({})

async function loadWallets(): Promise<void> {
  try {
    const items = await walletsApi.list()
    const next: Record<string, WalletItem> = {}
    for (const item of items) next[item.network] = item
    wallets.value = next
  } catch {
    /* advisory only — the page works without it */
  }
}

function tokenKey(code: string, symbol: string): string {
  return `${code}:${symbol}`
}

function seedDrafts(items: Network[]): void {
  for (const network of items) {
    drafts[network.code] = {
      confirmations_required: network.confirmations_required,
      is_enabled: network.is_enabled,
      explorer_tx_url: network.explorer_tx_url ?? '',
      explorer_address_url: network.explorer_address_url ?? '',
    }
    for (const token of network.token_contracts ?? network.tokens ?? []) {
      tokenDrafts[tokenKey(network.code, token.symbol)] = {
        contract_address: token.contract_address,
        decimals: token.decimals,
        is_enabled: token.is_enabled ?? true,
      }
    }
  }
}

function isNetworkDirty(network: Network): boolean {
  const draft = drafts[network.code]
  if (!draft) return false
  return (
    draft.confirmations_required !== network.confirmations_required ||
    draft.is_enabled !== network.is_enabled ||
    draft.explorer_tx_url !== (network.explorer_tx_url ?? '') ||
    draft.explorer_address_url !== (network.explorer_address_url ?? '')
  )
}

function isTokenDirty(network: Network, token: TokenContract): boolean {
  const draft = tokenDrafts[tokenKey(network.code, token.symbol)]
  if (!draft) return false
  return (
    draft.contract_address !== token.contract_address ||
    draft.decimals !== token.decimals ||
    draft.is_enabled !== (token.is_enabled ?? true)
  )
}

// Drafts follow whatever the store holds — the store may already be loading
// when this view mounts (the topbar health menu fetches too).
watch(() => store.items, (items) => seedDrafts(items), { immediate: true, deep: false })

async function load(silent = false): Promise<void> {
  if (silent) refreshing.value = true
  else loading.value = true
  try {
    await store.load(true)
  } catch (error) {
    reportError(error, 'Failed to load networks')
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

async function saveNetwork(network: Network): Promise<void> {
  const draft = drafts[network.code]
  if (!draft) return
  savingNetwork.value = network.code
  errors.value[network.code] = {}
  try {
    const updated = await networksApi.update(network.code as NetworkCode, {
      confirmations_required: Number(draft.confirmations_required),
      is_enabled: draft.is_enabled,
      explorer_tx_url: draft.explorer_tx_url.trim() || null,
      explorer_address_url: draft.explorer_address_url.trim() || null,
    })
    store.replace({ ...network, ...updated })
    toast.success(`${network.name} updated`)
  } catch (error) {
    errors.value[network.code] = fieldErrors(error)
    reportError(error, 'Could not update the network')
  } finally {
    savingNetwork.value = null
  }
}

/**
 * A contract address is what the watchers match Transfer logs against, so a
 * typo silently stops every deposit on that token from ever being seen. The
 * API stores whatever string it is given, so the shape is checked here before
 * the write goes out: EVM is 0x + 40 hex, Tron is base58 starting with T.
 */
const CONTRACT_SHAPE: Record<string, { re: RegExp; hint: string }> = {
  ethereum: { re: /^0x[0-9a-fA-F]{40}$/, hint: 'Expected an EVM address: 0x followed by 40 hex characters.' },
  bsc: { re: /^0x[0-9a-fA-F]{40}$/, hint: 'Expected an EVM address: 0x followed by 40 hex characters.' },
  tron: { re: /^T[1-9A-HJ-NP-Za-km-z]{33}$/, hint: 'Expected a Tron address: T followed by 33 base58 characters.' },
}

function contractError(code: string, address: string): string | null {
  const shape = CONTRACT_SHAPE[code]
  if (!shape || !address) return null
  return shape.re.test(address) ? null : shape.hint
}

async function saveToken(network: Network, token: TokenContract): Promise<void> {
  const key = tokenKey(network.code, token.symbol)
  const draft = tokenDrafts[key]
  if (!draft) return

  const address = draft.contract_address.trim()
  const invalid = contractError(network.code, address)
  errors.value[key] = invalid ? { contract_address: invalid } : {}
  if (invalid) {
    toast.error('Check the contract address', invalid)
    return
  }

  savingToken.value = key
  try {
    await networksApi.updateToken(network.code as NetworkCode, token.symbol as Currency, {
      contract_address: address,
      decimals: Number(draft.decimals),
      is_enabled: draft.is_enabled,
    })
    toast.success(`${token.symbol} on ${network.name} updated`)
    await load(true)
  } catch (error) {
    reportError(error, 'Could not update the token contract')
  } finally {
    savingToken.value = null
  }
}

function tokensOf(network: Network): TokenContract[] {
  return network.token_contracts ?? network.tokens ?? []
}

onMounted(() => {
  void load()
  void loadWallets()
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Networks"
      description="Chain configuration, confirmation thresholds and token contracts."
    >
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="refreshing" @click="load(true)">
          <RefreshCw :size="15" :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
          Refresh
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
      <Skeleton v-for="i in 3" :key="i" height="h-96" rounded="rounded-2xl" />
    </div>

    <EmptyState
      v-else-if="networks.length === 0"
      :icon="Blocks"
      title="No networks configured"
      description="Seed the networks table on the backend to start scanning chains."
    />

    <div v-else class="grid grid-cols-1 gap-4 xl:grid-cols-2">
      <section v-for="network in networks" :key="network.code" class="card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
          <div class="flex min-w-0 items-center gap-3">
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border bg-surface"
            >
              <NetworkIcon :network="network.code" :size="32" />
            </span>
            <div class="min-w-0">
              <h2 class="truncate text-sm font-semibold">{{ network.name }}</h2>
              <p class="mono truncate text-xs text-muted">
                {{ network.code }}<template v-if="network.chain_id"> · chain {{ network.chain_id }}</template>
              </p>
            </div>
          </div>
          <span class="inline-flex items-center gap-2 text-xs">
            <HealthDot :healthy="network.watcher_healthy" :enabled="network.is_enabled" />
            <span
              :class="
                !network.is_enabled ? 'text-muted' : network.watcher_healthy ? 'text-success' : 'text-danger'
              "
            >
              {{ !network.is_enabled ? 'Disabled' : network.watcher_healthy ? 'Healthy' : 'Degraded' }}
            </span>
          </span>
        </header>

        <div class="space-y-5 px-5 py-5">
          <div
            v-if="wallets[network.code]?.source === 'none'"
            class="flex flex-wrap items-start gap-2 rounded-xl border border-danger/25 bg-danger-soft px-3 py-2.5 text-xs text-danger"
          >
            <ShieldAlert :size="15" class="mt-px shrink-0" aria-hidden="true" />
            <p class="min-w-0 flex-1">
              No deposit wallet configured — this network cannot accept payments.
            </p>
            <RouterLink to="/admin/wallet" class="shrink-0 font-medium underline underline-offset-2">
              Configure wallet →
            </RouterLink>
          </div>

          <dl class="grid grid-cols-2 gap-3 rounded-xl border border-border bg-surface-2 p-3.5 text-xs">
            <div class="min-w-0">
              <dt class="text-muted">Last scanned block</dt>
              <dd class="mono mt-0.5 truncate text-text">{{ network.last_scanned_block ?? '—' }}</dd>
            </div>
            <div class="min-w-0 text-right">
              <dt class="text-muted">Watcher seen</dt>
              <dd class="mt-0.5 truncate text-text">{{ formatRelative(network.watcher_seen_at) }}</dd>
            </div>
          </dl>

          <form
            v-if="drafts[network.code]"
            class="space-y-4"
            novalidate
            @submit.prevent="saveNetwork(network)"
          >
            <!-- A viewer may read the configuration but not edit it (403 server-side). -->
            <fieldset class="space-y-4" :disabled="!auth.isAdmin" :title="adminOnlyTitle">
            <label class="flex cursor-pointer items-center justify-between gap-4">
              <span class="min-w-0">
                <span class="block text-sm font-medium">Enabled</span>
                <span class="block text-xs text-muted">Watchers only scan enabled networks.</span>
              </span>
              <span class="relative inline-flex shrink-0">
                <input
                  v-model="drafts[network.code]!.is_enabled"
                  type="checkbox"
                  class="peer sr-only"
                  :aria-label="`Enable ${network.name}`"
                />
                <span
                  class="h-6 w-11 rounded-full bg-border-strong transition-colors peer-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2"
                  aria-hidden="true"
                />
                <span
                  class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"
                  aria-hidden="true"
                />
              </span>
            </label>

            <div>
              <label :for="`conf-${network.code}`" class="label">Confirmations required</label>
              <input
                :id="`conf-${network.code}`"
                v-model.number="drafts[network.code]!.confirmations_required"
                type="number"
                min="1"
                max="200"
                class="input mono max-w-[140px]"
                :class="errors[network.code]?.confirmations_required ? 'input-error' : ''"
              />
              <p v-if="errors[network.code]?.confirmations_required" class="error-text">
                {{ errors[network.code]?.confirmations_required }}
              </p>
            </div>

            <div>
              <label :for="`tx-${network.code}`" class="label">Explorer tx URL</label>
              <input
                :id="`tx-${network.code}`"
                v-model="drafts[network.code]!.explorer_tx_url"
                type="text"
                class="input mono text-xs"
                placeholder="https://etherscan.io/tx/{hash}"
              />
            </div>

            <div>
              <label :for="`addr-${network.code}`" class="label">Explorer address URL</label>
              <input
                :id="`addr-${network.code}`"
                v-model="drafts[network.code]!.explorer_address_url"
                type="text"
                class="input mono text-xs"
                placeholder="https://etherscan.io/address/{address}"
              />
            </div>

            </fieldset>

            <button
              type="submit"
              class="btn-primary btn-sm"
              :disabled="!auth.isAdmin || savingNetwork === network.code || !isNetworkDirty(network)"
              :title="adminOnlyTitle"
            >
              <Spinner v-if="savingNetwork === network.code" :size="13" />
              <Save v-else :size="14" aria-hidden="true" />
              Save network
            </button>
            <p v-if="!auth.isAdmin" class="text-xs text-muted">Read-only — admins can edit.</p>
          </form>

          <!-- Token contracts -->
          <div class="border-t border-border pt-5">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Token contracts</h3>
            <div class="mt-3 space-y-3">
              <form
                v-for="token in tokensOf(network)"
                :key="token.symbol"
                class="rounded-xl border border-border bg-surface-2 p-3.5"
                novalidate
                @submit.prevent="saveToken(network, token)"
              >
                <fieldset :disabled="!auth.isAdmin" :title="adminOnlyTitle">
                <div class="flex items-center justify-between gap-3">
                  <span class="inline-flex items-center gap-2 text-sm font-semibold">
                    <CoinLogo :currency="token.symbol" :size="26" />
                    {{ token.symbol }}
                  </span>
                  <label class="flex cursor-pointer items-center gap-2 text-xs text-muted">
                    <input
                      v-if="tokenDrafts[tokenKey(network.code, token.symbol)]"
                      v-model="tokenDrafts[tokenKey(network.code, token.symbol)]!.is_enabled"
                      type="checkbox"
                      class="checkbox"
                    />
                    Enabled
                  </label>
                </div>

                <div
                  v-if="tokenDrafts[tokenKey(network.code, token.symbol)]"
                  class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_88px]"
                >
                  <div>
                    <label :for="`c-${network.code}-${token.symbol}`" class="label">Contract address</label>
                    <input
                      :id="`c-${network.code}-${token.symbol}`"
                      v-model="tokenDrafts[tokenKey(network.code, token.symbol)]!.contract_address"
                      type="text"
                      class="input mono text-xs"
                      :class="errors[tokenKey(network.code, token.symbol)]?.contract_address ? 'input-error' : ''"
                      :aria-invalid="Boolean(errors[tokenKey(network.code, token.symbol)]?.contract_address)"
                    />
                    <p
                      v-if="errors[tokenKey(network.code, token.symbol)]?.contract_address"
                      class="error-text"
                    >
                      {{ errors[tokenKey(network.code, token.symbol)]?.contract_address }}
                    </p>
                  </div>
                  <div>
                    <label :for="`d-${network.code}-${token.symbol}`" class="label">Decimals</label>
                    <input
                      :id="`d-${network.code}-${token.symbol}`"
                      v-model.number="tokenDrafts[tokenKey(network.code, token.symbol)]!.decimals"
                      type="number"
                      min="0"
                      max="36"
                      class="input mono text-xs"
                    />
                  </div>
                </div>

                </fieldset>

                <button
                  type="submit"
                  class="btn-secondary btn-sm mt-3"
                  :disabled="!auth.isAdmin || savingToken === tokenKey(network.code, token.symbol) || !isTokenDirty(network, token)"
                  :title="adminOnlyTitle"
                >
                  <Spinner v-if="savingToken === tokenKey(network.code, token.symbol)" :size="13" />
                  <Save v-else :size="13" aria-hidden="true" />
                  Save {{ token.symbol }}
                </button>
              </form>

              <p v-if="tokensOf(network).length === 0" class="text-xs text-muted">
                No token contracts configured for this network.
              </p>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
