<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ArrowLeftRight } from 'lucide-vue-next'
import AddressDisplay from '@/components/AddressDisplay.vue'
import AmountDisplay from '@/components/AmountDisplay.vue'
import DataTable from '@/components/DataTable.vue'
import Drawer from '@/components/Drawer.vue'
import EmptyState from '@/components/EmptyState.vue'
import FilterBar from '@/components/FilterBar.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import SelectFilter from '@/components/SelectFilter.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
import { transactionsApi } from '@/api/transactions'
import type { Transaction } from '@/api/types'
import { usePaginatedList } from '@/composables/usePaginatedList'
import { formatDateTime, formatRelative, truncateMiddle } from '@/utils/format'
import { CURRENCY_OPTIONS, NETWORK_OPTIONS, TRANSACTION_STATUS_OPTIONS } from '@/utils/options'

const { filters, items, meta, loading, hasFilters, load, setPage, resetFilters } = usePaginatedList<
  Transaction,
  Record<'q' | 'status' | 'network' | 'currency', string>
>({
  defaultFilters: { q: '', status: '', network: '', currency: '' },
  fetcher: (params) => transactionsApi.list(params),
  perPage: 25,
})

const selected = ref<Transaction | null>(null)

const columns: Column[] = [
  { key: 'tx_hash', label: 'Transaction' },
  { key: 'to_address', label: 'To', hideBelow: 'lg' },
  { key: 'amount', label: 'Amount', class: 'text-right' },
  { key: 'network', label: 'Network', hideBelow: 'sm' },
  { key: 'confirmations', label: 'Conf.', class: 'text-right', hideBelow: 'md' },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Seen', class: 'text-right', hideBelow: 'lg' },
]

onMounted(() => void load())
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Transactions"
      description="Every on-chain transfer detected by the watchers."
    />

    <section class="card overflow-hidden">
      <FilterBar
        v-model:search="filters.q"
        search-label="Search by hash or address"
        :has-filters="hasFilters"
        @reset="resetFilters"
      >
        <SelectFilter v-model="filters.status" label="Status" :options="TRANSACTION_STATUS_OPTIONS" />
        <SelectFilter v-model="filters.network" label="Network" :options="NETWORK_OPTIONS" />
        <SelectFilter v-model="filters.currency" label="Currency" :options="CURRENCY_OPTIONS" />
      </FilterBar>

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        clickable
        caption="Transactions"
        :row-key="(row) => `${row.network}-${row.tx_hash}-${row.log_index}`"
        @row-click="selected = $event"
      >
        <template #cell-tx_hash="{ row }">
          <div class="min-w-0">
            <AddressDisplay
              :value="row.tx_hash"
              :href="row.explorer_url"
              label="Transaction hash"
              :head="10"
              :tail="8"
            />
            <p v-if="row.merchant" class="truncate text-xs text-muted">{{ row.merchant.name }}</p>
          </div>
        </template>
        <template #cell-to_address="{ row }">
          <AddressDisplay :value="row.to_address" label="Address" :head="8" :tail="6" size="sm" />
        </template>
        <template #cell-amount="{ row }">
          <AmountDisplay :value="row.amount" :currency="row.currency" size="sm" />
        </template>
        <template #cell-network="{ row }">
          <NetworkBadge :network="row.network" size="sm" compact />
        </template>
        <template #cell-confirmations="{ row }">
          <span class="mono tabular-nums text-muted">{{ row.confirmations }}</span>
        </template>
        <template #cell-status="{ row }">
          <StatusBadge :status="row.status" size="sm" context="Transaction status" />
        </template>
        <template #cell-created_at="{ row }">
          <span class="whitespace-nowrap text-xs text-muted">{{ formatRelative(row.created_at) }}</span>
        </template>
        <template #empty>
          <EmptyState
            :icon="ArrowLeftRight"
            title="No transactions"
            :description="
              hasFilters
                ? 'No transaction matches the current filters.'
                : 'Transfers detected by the watchers will appear here.'
            "
          >
            <button v-if="hasFilters" type="button" class="btn-secondary" @click="resetFilters">
              Clear filters
            </button>
          </EmptyState>
        </template>
      </DataTable>

      <Pagination :meta="meta" :disabled="loading" @change="setPage" />
    </section>

    <Drawer :open="selected !== null" title="Transaction details" @close="selected = null">
      <dl v-if="selected" class="space-y-4 text-sm">
        <div>
          <dt class="text-xs text-muted">Hash</dt>
          <dd class="mt-1">
            <AddressDisplay :value="selected.tx_hash" :href="selected.explorer_url" label="Transaction hash" full />
          </dd>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <dt class="text-xs text-muted">Amount</dt>
            <dd class="mt-1"><AmountDisplay :value="selected.amount" :currency="selected.currency" /></dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Status</dt>
            <dd class="mt-1"><StatusBadge :status="selected.status" size="sm" /></dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Network</dt>
            <dd class="mt-1"><NetworkBadge :network="selected.network" size="sm" /></dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Confirmations</dt>
            <dd class="mono mt-1 tabular-nums">
              {{ selected.confirmations }}<template v-if="selected.confirmations_required">/{{ selected.confirmations_required }}</template>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Block</dt>
            <dd class="mono mt-1 truncate">{{ selected.block_number ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Log index</dt>
            <dd class="mono mt-1">{{ selected.log_index }}</dd>
          </div>
        </div>
        <div>
          <dt class="text-xs text-muted">From</dt>
          <dd class="mt-1"><AddressDisplay :value="selected.from_address" label="From address" full /></dd>
        </div>
        <div>
          <dt class="text-xs text-muted">To</dt>
          <dd class="mt-1"><AddressDisplay :value="selected.to_address" label="To address" full /></dd>
        </div>
        <div>
          <dt class="text-xs text-muted">Contract</dt>
          <dd class="mt-1"><AddressDisplay :value="selected.contract_address" label="Contract" full /></dd>
        </div>
        <div>
          <dt class="text-xs text-muted">Raw amount</dt>
          <dd class="mono mt-1 break-all text-muted">{{ selected.amount_raw }}</dd>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <dt class="text-xs text-muted">Detected</dt>
            <dd class="mono mt-1 text-xs">{{ formatDateTime(selected.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Credited</dt>
            <dd class="mono mt-1 text-xs">{{ formatDateTime(selected.credited_at) }}</dd>
          </div>
        </div>
        <div v-if="selected.invoice_id">
          <dt class="text-xs text-muted">Invoice</dt>
          <dd class="mt-1">
            <RouterLink
              :to="{ name: 'invoice-detail', params: { id: selected.invoice_id } }"
              class="link mono"
              @click="selected = null"
            >
              {{ truncateMiddle(selected.invoice_id, 10, 8) }}
            </RouterLink>
          </dd>
        </div>
      </dl>
    </Drawer>
  </div>
</template>
