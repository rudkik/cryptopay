<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { FileText } from 'lucide-vue-next'
import AmountDisplay from '@/components/AmountDisplay.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import FilterBar from '@/components/FilterBar.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import SelectFilter from '@/components/SelectFilter.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
import { invoicesApi } from '@/api/invoices'
import { merchantsApi } from '@/api/merchants'
import type { Invoice } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { usePaginatedList } from '@/composables/usePaginatedList'
import { formatRelative, truncateMiddle } from '@/utils/format'
import {
  CURRENCY_OPTIONS,
  INVOICE_STATUS_OPTIONS,
  NETWORK_OPTIONS,
  type Option,
} from '@/utils/options'

const router = useRouter()
const auth = useAuthStore()

const { filters, items, meta, loading, hasFilters, load, setPage, resetFilters } = usePaginatedList<
  Invoice,
  Record<'q' | 'status' | 'network' | 'currency' | 'merchant_id', string>
>({
  defaultFilters: { q: '', status: '', network: '', currency: '', merchant_id: '' },
  fetcher: (params) => invoicesApi.list(params),
  perPage: 25,
})

const serviceOptions = ref<Option[]>([])

const columns: Column[] = [
  { key: 'id', label: 'Invoice' },
  { key: 'merchant', label: 'Service', hideBelow: 'lg' },
  { key: 'amount', label: 'Amount', class: 'text-right' },
  { key: 'progress', label: 'Received', class: 'text-right', hideBelow: 'md' },
  { key: 'network', label: 'Network', hideBelow: 'sm' },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Created', class: 'text-right', hideBelow: 'lg' },
]

const activeSummary = computed(() => `${meta.value.total} invoice${meta.value.total === 1 ? '' : 's'}`)

function open(invoice: Invoice): void {
  void router.push({ name: 'invoice-detail', params: { id: invoice.id } })
}

async function loadServices(): Promise<void> {
  try {
    const response = await merchantsApi.list({ per_page: 100 })
    serviceOptions.value = (response.data ?? []).map((m) => ({ value: m.id, label: m.name }))
  } catch {
    // The filter simply stays unavailable if services cannot be listed.
    serviceOptions.value = []
  }
}

onMounted(() => {
  void load()
  void loadServices()
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Invoices" :description="`All payment and token-sale invoices — ${activeSummary}.`" />

    <section class="card overflow-hidden">
      <FilterBar
        v-model:search="filters.q"
        search-label="Search by id, external id or address"
        :has-filters="hasFilters"
        @reset="resetFilters"
      >
        <SelectFilter v-model="filters.status" label="Status" :options="INVOICE_STATUS_OPTIONS" />
        <SelectFilter v-model="filters.network" label="Network" :options="NETWORK_OPTIONS" />
        <SelectFilter v-model="filters.currency" label="Currency" :options="CURRENCY_OPTIONS" />
        <SelectFilter
          v-if="serviceOptions.length"
          v-model="filters.merchant_id"
          label="Service"
          placeholder="All services"
          :options="serviceOptions"
        />
      </FilterBar>

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        clickable
        caption="Invoices"
        @row-click="open"
      >
        <template #cell-id="{ row }">
          <div class="min-w-0">
            <p class="mono truncate text-text">{{ truncateMiddle(row.id, 8, 6) }}</p>
            <p v-if="row.external_id" class="truncate text-xs text-muted">{{ row.external_id }}</p>
            <p
              v-else-if="auth.tokenSaleEnabled && row.type === 'token_purchase'"
              class="text-xs text-accent-ink"
            >
              Token purchase
            </p>
          </div>
        </template>
        <template #cell-merchant="{ row }">
          <span class="truncate text-muted">{{ row.merchant?.name ?? '—' }}</span>
        </template>
        <template #cell-amount="{ row }">
          <AmountDisplay :value="row.amount" :currency="row.currency" size="sm" />
        </template>
        <template #cell-progress="{ row }">
          <AmountDisplay
            :value="row.amount_confirmed"
            size="sm"
            :muted="row.amount_confirmed === '0'"
          />
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
            :icon="FileText"
            title="No invoices found"
            :description="
              hasFilters
                ? 'No invoice matches the current filters.'
                : 'Invoices created through the merchant API will appear here.'
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
  </div>
</template>
