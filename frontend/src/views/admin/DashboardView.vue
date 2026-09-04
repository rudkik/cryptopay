<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowUpRight,
  CheckCircle2,
  FileText,
  RefreshCw,
  Store,
  TrendingUp,
  Webhook,
} from 'lucide-vue-next'
import AmountDisplay from '@/components/AmountDisplay.vue'
import DataTable from '@/components/DataTable.vue'
import type { Column } from '@/components/table'
import EmptyState from '@/components/EmptyState.vue'
import HealthDot from '@/components/HealthDot.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import Skeleton from '@/components/Skeleton.vue'
import StatTile from '@/components/StatTile.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import VolumeChart from '@/components/VolumeChart.vue'
import { dashboardApi } from '@/api/dashboard'
import { walletsApi } from '@/api/wallets'
import type { DashboardData, Invoice, WalletSource } from '@/api/types'
import { reportError } from '@/composables/useErrorHandler'
import { formatCount, formatRelative, truncateMiddle } from '@/utils/format'

const router = useRouter()

const data = ref<DashboardData | null>(null)
const loading = ref(true)
const refreshing = ref(false)

const stats = computed(() => data.value?.stats ?? null)
const chartPoints = computed(() => data.value?.chart ?? [])
const recentInvoices = computed(() => data.value?.recent_invoices ?? [])
const networks = computed(() => data.value?.networks ?? [])

const NETWORK_NAMES: Record<string, string> = {
  ethereum: 'Ethereum',
  bsc: 'BNB Smart Chain',
  tron: 'Tron',
}

/**
 * Deposit-wallet source per network — one cheap call, rendered as a dot next to
 * the network badge. Failures are swallowed: the hint simply does not appear.
 */
const walletSources = ref<Record<string, WalletSource>>({})

const WALLET_HINTS: Record<WalletSource, { label: string; dot: string; text: string; title: string }> = {
  database: { label: 'db', dot: 'bg-success', text: 'text-muted', title: 'Deposit wallet: key stored in the database' },
  env: { label: 'env', dot: 'bg-primary', text: 'text-muted', title: 'Deposit wallet: key from the watcher environment' },
  none: { label: 'none', dot: 'bg-danger', text: 'text-danger', title: 'No deposit wallet configured for this network' },
}

async function loadWalletSources(): Promise<void> {
  try {
    const items = await walletsApi.list()
    const next: Record<string, WalletSource> = {}
    for (const item of items) next[item.network] = item.source
    walletSources.value = next
  } catch {
    /* advisory only */
  }
}

const columns: Column[] = [
  { key: 'id', label: 'Invoice' },
  { key: 'merchant', label: 'Merchant', hideBelow: 'md' },
  { key: 'amount', label: 'Amount', class: 'text-right' },
  { key: 'network', label: 'Network', hideBelow: 'sm' },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Created', class: 'text-right', hideBelow: 'lg' },
]

async function load(silent = false): Promise<void> {
  if (silent) refreshing.value = true
  else loading.value = true
  try {
    data.value = await dashboardApi.fetch()
  } catch (error) {
    reportError(error, 'Failed to load the dashboard')
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

function openInvoice(invoice: Invoice): void {
  void router.push({ name: 'invoice-detail', params: { id: invoice.id } })
}

onMounted(() => {
  void load()
  void loadWalletSources()
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Dashboard" description="Live overview of settlement, watchers and webhooks.">
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="refreshing" @click="load(true)">
          <RefreshCw :size="15" :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
          Refresh
        </button>
      </template>
    </PageHeader>

    <!-- Stat tiles -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatTile label="Invoices" :icon="FileText" :loading="loading"
        :hint="stats ? `${formatCount(stats.invoices_paid)} paid` : undefined">
        <p class="text-2xl font-semibold tabular-nums">{{ formatCount(stats?.invoices_total) }}</p>
      </StatTile>

      <StatTile label="24h volume" :icon="TrendingUp" :loading="loading" tone="success">
        <div class="space-y-1">
          <div><AmountDisplay :value="stats?.volume_24h?.USDT" currency="USDT" size="lg" :max-decimals="2" logo /></div>
          <div><AmountDisplay :value="stats?.volume_24h?.USDC" currency="USDC" size="lg" :max-decimals="2" logo /></div>
        </div>
      </StatTile>

      <StatTile label="Active merchants" :icon="Store" :loading="loading">
        <p class="text-2xl font-semibold tabular-nums">{{ formatCount(stats?.merchants_active) }}</p>
      </StatTile>

      <StatTile
        label="Pending webhooks"
        :icon="Webhook"
        :loading="loading"
        :tone="(stats?.pending_webhooks ?? 0) > 0 ? 'warning' : 'primary'"
      >
        <p class="text-2xl font-semibold tabular-nums">{{ formatCount(stats?.pending_webhooks) }}</p>
      </StatTile>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
      <!-- Chart -->
      <section class="card p-5 xl:col-span-2">
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold">Settled volume</h2>
            <p class="mt-0.5 text-xs text-muted">Last 30 days, by currency</p>
          </div>
          <div v-if="stats" class="text-right">
            <p class="text-[11px] uppercase tracking-wide text-muted">Total</p>
            <!-- The chart plots both series, so the total must cover both currencies. -->
            <div><AmountDisplay :value="stats.volume_total?.USDT" currency="USDT" size="sm" :max-decimals="2" logo /></div>
            <div><AmountDisplay :value="stats.volume_total?.USDC" currency="USDC" size="sm" :max-decimals="2" muted logo /></div>
          </div>
        </div>
        <Skeleton v-if="loading" height="h-64" rounded="rounded-xl" />
        <VolumeChart v-else-if="chartPoints.length" :points="chartPoints" />
        <EmptyState
          v-else
          :icon="TrendingUp"
          title="No volume yet"
          description="Confirmed payments will appear here once the first invoice settles."
          compact
        />
      </section>

      <!-- Network health -->
      <section class="card p-5">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-sm font-semibold">Watcher health</h2>
          <RouterLink to="/admin/networks" class="text-xs text-primary-hover hover:underline">
            Manage
          </RouterLink>
        </div>

        <div v-if="loading" class="space-y-3">
          <Skeleton v-for="i in 3" :key="i" height="h-[72px]" rounded="rounded-xl" />
        </div>

        <ul v-else-if="networks.length" class="space-y-3">
          <li
            v-for="network in networks"
            :key="network.code"
            class="rounded-xl border border-border bg-surface-2 p-3.5"
          >
            <div class="flex items-center justify-between gap-3">
              <span class="flex min-w-0 items-center gap-2">
                <NetworkBadge
                  :network="network.code"
                  :name="network.name ?? NETWORK_NAMES[network.code]"
                  size="sm"
                />
                <span
                  v-if="walletSources[network.code]"
                  class="inline-flex shrink-0 items-center gap-1 text-[10px]"
                  :class="WALLET_HINTS[walletSources[network.code]].text"
                  :title="WALLET_HINTS[walletSources[network.code]].title"
                >
                  <span
                    class="h-1.5 w-1.5 rounded-full"
                    :class="WALLET_HINTS[walletSources[network.code]].dot"
                    aria-hidden="true"
                  />
                  {{ WALLET_HINTS[walletSources[network.code]].label }}
                </span>
              </span>
              <span class="inline-flex items-center gap-1.5 text-[11px]">
                <HealthDot :healthy="network.watcher_healthy" :enabled="network.is_enabled" />
                <span
                  :class="
                    !network.is_enabled
                      ? 'text-muted'
                      : network.watcher_healthy
                        ? 'text-success'
                        : 'text-danger'
                  "
                >
                  {{ !network.is_enabled ? 'Disabled' : network.watcher_healthy ? 'Healthy' : 'Degraded' }}
                </span>
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
              <div>
                <dt class="text-muted">Last block</dt>
                <dd class="mono mt-0.5 truncate text-text">{{ network.last_scanned_block ?? '—' }}</dd>
              </div>
              <div class="text-right">
                <dt class="text-muted">Seen</dt>
                <dd class="mt-0.5 truncate text-text">{{ formatRelative(network.watcher_seen_at) }}</dd>
              </div>
            </dl>
          </li>
        </ul>

        <EmptyState v-else title="No networks configured" compact />
      </section>
    </div>

    <!-- Recent invoices -->
    <section class="card overflow-hidden">
      <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
        <h2 class="text-sm font-semibold">Recent invoices</h2>
        <RouterLink to="/admin/invoices" class="inline-flex items-center gap-1 text-xs text-primary-hover hover:underline">
          View all
          <ArrowUpRight :size="13" aria-hidden="true" />
        </RouterLink>
      </div>

      <DataTable
        :columns="columns"
        :rows="recentInvoices"
        :loading="loading"
        :skeleton-rows="5"
        clickable
        caption="Recently created invoices"
        @row-click="openInvoice"
      >
        <template #cell-id="{ row }">
          <div class="min-w-0">
            <p class="mono truncate text-text">{{ truncateMiddle(row.id, 8, 6) }}</p>
            <p v-if="row.external_id" class="truncate text-xs text-muted">{{ row.external_id }}</p>
          </div>
        </template>
        <template #cell-merchant="{ row }">
          <span class="truncate text-muted">{{ row.merchant?.name ?? '—' }}</span>
        </template>
        <template #cell-amount="{ row }">
          <AmountDisplay :value="row.amount" :currency="row.currency" size="sm" />
        </template>
        <template #cell-network="{ row }">
          <NetworkBadge v-if="row.network" :network="row.network" size="sm" compact />
          <span v-else class="text-muted">—</span>
        </template>
        <template #cell-status="{ row }">
          <StatusBadge :status="row.status" size="sm" context="Invoice status" />
        </template>
        <template #cell-created_at="{ row }">
          <span class="whitespace-nowrap text-xs text-muted">{{ formatRelative(row.created_at) }}</span>
        </template>
        <template #empty>
          <EmptyState
            :icon="CheckCircle2"
            title="No invoices yet"
            description="Invoices created through the merchant API will show up here."
            compact
          />
        </template>
      </DataTable>
    </section>
  </div>
</template>
