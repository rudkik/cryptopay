<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RotateCw, Webhook } from 'lucide-vue-next'
import DataTable from '@/components/DataTable.vue'
import Drawer from '@/components/Drawer.vue'
import EmptyState from '@/components/EmptyState.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import SelectFilter from '@/components/SelectFilter.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
import { webhooksApi } from '@/api/webhooks'
import { merchantsApi } from '@/api/merchants'
import type { WebhookDelivery } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { usePaginatedList } from '@/composables/usePaginatedList'
import { reportError } from '@/composables/useErrorHandler'
import { formatDateTime, formatRelative, truncateMiddle } from '@/utils/format'
import { toast } from '@/utils/toast'
import { WEBHOOK_STATUS_OPTIONS, type Option } from '@/utils/options'

const auth = useAuthStore()

const { filters, items, meta, loading, hasFilters, load, setPage, resetFilters } = usePaginatedList<
  WebhookDelivery,
  Record<'status' | 'merchant_id' | 'event', string>
>({
  defaultFilters: { status: '', merchant_id: '', event: '' },
  fetcher: (params) => webhooksApi.list(params),
  perPage: 25,
})

const serviceOptions = ref<Option[]>([])
const selected = ref<WebhookDelivery | null>(null)
const retrying = ref<string | null>(null)

// `token_purchase.completed` only ever fires while the optional token sale
// module is enabled (SPEC §8), so the filter drops it when it is off.
const EVENT_OPTIONS = computed<Option[]>(() => [
  { value: 'invoice.confirming', label: 'invoice.confirming' },
  { value: 'invoice.paid', label: 'invoice.paid' },
  { value: 'invoice.overpaid', label: 'invoice.overpaid' },
  { value: 'invoice.partially_paid', label: 'invoice.partially_paid' },
  { value: 'invoice.expired', label: 'invoice.expired' },
  { value: 'invoice.cancelled', label: 'invoice.cancelled' },
  ...(auth.tokenSaleEnabled
    ? [{ value: 'token_purchase.completed', label: 'token_purchase.completed' }]
    : []),
])

const columns: Column[] = [
  { key: 'event', label: 'Event' },
  { key: 'merchant', label: 'Service', hideBelow: 'lg' },
  { key: 'status', label: 'Status' },
  { key: 'attempts', label: 'Attempts', class: 'text-right', hideBelow: 'sm' },
  { key: 'response_code', label: 'Response', class: 'text-right', hideBelow: 'sm' },
  { key: 'next_attempt_at', label: 'Next attempt', class: 'text-right', hideBelow: 'md' },
  { key: 'actions', label: '', class: 'text-right w-px' },
]

async function retry(delivery: WebhookDelivery): Promise<void> {
  retrying.value = delivery.id
  try {
    await webhooksApi.retry(delivery.id)
    toast.success('Delivery re-queued', delivery.event)
    await load()
  } catch (error) {
    reportError(error, 'Could not retry the delivery')
  } finally {
    retrying.value = null
  }
}

async function loadServices(): Promise<void> {
  try {
    const response = await merchantsApi.list({ per_page: 100 })
    serviceOptions.value = (response.data ?? []).map((m) => ({ value: m.id, label: m.name }))
  } catch {
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
    <PageHeader
      title="Webhook deliveries"
      description="Outgoing events with retry schedule — 1m, 5m, 30m, 2h, 6h, 24h."
    />

    <section class="card overflow-hidden">
      <FilterBar :has-filters="hasFilters" @reset="resetFilters">
        <SelectFilter v-model="filters.status" label="Status" :options="WEBHOOK_STATUS_OPTIONS" />
        <SelectFilter v-model="filters.event" label="Event" :options="EVENT_OPTIONS" />
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
        caption="Webhook deliveries"
        @row-click="selected = $event"
      >
        <template #cell-event="{ row }">
          <div class="min-w-0">
            <p class="mono truncate text-text">{{ row.event }}</p>
            <p class="truncate text-xs text-muted">{{ row.url }}</p>
          </div>
        </template>
        <template #cell-merchant="{ row }">
          <span class="truncate text-muted">{{ row.merchant?.name ?? '—' }}</span>
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
            :disabled="retrying === row.id"
            :aria-label="`Retry ${row.event}`"
            @click.stop="retry(row)"
          >
            <Spinner v-if="retrying === row.id" :size="13" />
            <RotateCw v-else :size="13" aria-hidden="true" />
            Retry
          </button>
        </template>
        <template #empty>
          <EmptyState
            :icon="Webhook"
            title="No deliveries"
            :description="
              hasFilters
                ? 'No delivery matches the current filters.'
                : 'Events are queued once a service has a webhook URL configured.'
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

    <Drawer :open="selected !== null" title="Delivery details" @close="selected = null">
      <dl v-if="selected" class="space-y-4 text-sm">
        <div class="grid grid-cols-2 gap-4">
          <div class="min-w-0">
            <dt class="text-xs text-muted">Event</dt>
            <dd class="mono mt-1 truncate">{{ selected.event }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Status</dt>
            <dd class="mt-1"><StatusBadge :status="selected.status" size="sm" /></dd>
          </div>
        </div>
        <div class="min-w-0">
          <dt class="text-xs text-muted">Endpoint</dt>
          <dd class="mono mt-1 break-all text-muted">{{ selected.url }}</dd>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <dt class="text-xs text-muted">Attempts</dt>
            <dd class="mono mt-1">{{ selected.attempts }}/{{ selected.max_attempts }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Response code</dt>
            <dd class="mono mt-1">{{ selected.response_code ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Next attempt</dt>
            <dd class="mono mt-1 text-xs">{{ formatDateTime(selected.next_attempt_at) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Delivered</dt>
            <dd class="mono mt-1 text-xs">{{ formatDateTime(selected.delivered_at) }}</dd>
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
        <div v-if="selected.last_error">
          <dt class="text-xs text-muted">Last error</dt>
          <dd
            class="mono mt-1 max-h-32 overflow-auto rounded-lg border border-danger/25 bg-danger-soft p-3 text-xs text-danger"
          >
            {{ selected.last_error }}
          </dd>
        </div>
        <div v-if="selected.response_body">
          <dt class="text-xs text-muted">Response body</dt>
          <dd
            class="mono mt-1 max-h-40 overflow-auto rounded-lg border border-border bg-surface-2 p-3 text-xs text-muted"
          >
            {{ selected.response_body }}
          </dd>
        </div>
        <div v-if="selected.payload">
          <dt class="text-xs text-muted">Payload</dt>
          <dd>
            <pre
              class="mono mt-1 max-h-64 overflow-auto rounded-lg border border-border bg-surface-2 p-3 text-xs text-muted"
            >{{ JSON.stringify(selected.payload, null, 2) }}</pre>
          </dd>
        </div>
      </dl>

      <template #footer>
        <button
          v-if="selected"
          type="button"
          class="btn-primary w-full"
          :disabled="retrying === selected.id"
          @click="retry(selected)"
        >
          <Spinner v-if="retrying === selected.id" :size="14" />
          <RotateCw v-else :size="14" aria-hidden="true" />
          Retry delivery
        </button>
      </template>
    </Drawer>
  </div>
</template>
