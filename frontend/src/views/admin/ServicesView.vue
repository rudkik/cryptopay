<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Plug, Plus } from 'lucide-vue-next'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import FilterBar from '@/components/FilterBar.vue'
import Modal from '@/components/Modal.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
// The API vocabulary is unchanged (SPEC §6.4): a "service" in the admin UI is
// a `merchant` on the wire — one connected project with its own API keys.
import { merchantsApi, type MerchantPayload } from '@/api/merchants'
import type { Merchant } from '@/api/types'
import { usePaginatedList } from '@/composables/usePaginatedList'
import { fieldErrors, reportError } from '@/composables/useErrorHandler'
import { formatDate, truncateMiddle } from '@/utils/format'
import { toast } from '@/utils/toast'

const router = useRouter()

const { filters, items, meta, loading, hasFilters, load, setPage, resetFilters } = usePaginatedList<
  Merchant,
  Record<'q', string>
>({
  defaultFilters: { q: '' },
  fetcher: (params) => merchantsApi.list(params),
  perPage: 25,
})

const columns: Column[] = [
  { key: 'name', label: 'Service' },
  { key: 'email', label: 'Email', hideBelow: 'md' },
  { key: 'webhook_url', label: 'Webhook', hideBelow: 'lg' },
  { key: 'is_active', label: 'Status' },
  { key: 'created_at', label: 'Created', class: 'text-right', hideBelow: 'sm' },
]

const createOpen = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const form = reactive<MerchantPayload>({ name: '', email: '', webhook_url: '', is_active: true })

function openCreate(): void {
  form.name = ''
  form.email = ''
  form.webhook_url = ''
  form.is_active = true
  errors.value = {}
  createOpen.value = true
}

async function submit(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const service = await merchantsApi.create({
      name: form.name.trim(),
      email: form.email?.trim() || null,
      webhook_url: form.webhook_url?.trim() || null,
      is_active: form.is_active,
    })
    toast.success('Service created', service.name)
    createOpen.value = false
    void router.push({ name: 'service-detail', params: { id: service.id } })
  } catch (error) {
    errors.value = fieldErrors(error)
    reportError(error, 'Could not create the service')
  } finally {
    saving.value = false
  }
}

function open(service: Merchant): void {
  void router.push({ name: 'service-detail', params: { id: service.id } })
}

onMounted(() => void load())
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Services"
      description="Your connected projects — each one gets API keys, a webhook URL and its own balances."
    >
      <template #actions>
        <button type="button" class="btn-primary" @click="openCreate">
          <Plus :size="15" aria-hidden="true" />
          New service
        </button>
      </template>
    </PageHeader>

    <section class="card overflow-hidden">
      <FilterBar
        v-model:search="filters.q"
        search-label="Search services"
        :has-filters="hasFilters"
        @reset="resetFilters"
      />

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        clickable
        caption="Services"
        @row-click="open"
      >
        <template #cell-name="{ row }">
          <div class="min-w-0">
            <p class="truncate font-medium text-text">{{ row.name }}</p>
            <p class="mono truncate text-xs text-muted">{{ truncateMiddle(row.id, 8, 6) }}</p>
          </div>
        </template>
        <template #cell-email="{ row }">
          <span class="truncate text-muted">{{ row.email ?? '—' }}</span>
        </template>
        <template #cell-webhook_url="{ row }">
          <span class="mono block max-w-[260px] truncate text-xs text-muted">
            {{ row.webhook_url ?? '—' }}
          </span>
        </template>
        <template #cell-is_active="{ row }">
          <StatusBadge :status="row.is_active ? 'active' : 'inactive'" size="sm" />
        </template>
        <template #cell-created_at="{ row }">
          <span class="whitespace-nowrap text-xs text-muted">{{ formatDate(row.created_at) }}</span>
        </template>
        <template #empty>
          <EmptyState
            :icon="Plug"
            title="No services yet"
            description="Add a service to issue API keys and start accepting payments."
          >
            <button type="button" class="btn-primary" @click="openCreate">
              <Plus :size="15" aria-hidden="true" />
              New service
            </button>
          </EmptyState>
        </template>
      </DataTable>

      <Pagination :meta="meta" :disabled="loading" @change="setPage" />
    </section>

    <Modal
      :open="createOpen"
      title="New service"
      description="A webhook secret is generated automatically."
      @close="createOpen = false"
    >
      <form id="service-form" class="space-y-4" novalidate @submit.prevent="submit">
        <div>
          <label for="m-name" class="label">Name <span class="text-danger">*</span></label>
          <input
            id="m-name"
            v-model="form.name"
            type="text"
            required
            data-autofocus
            class="input"
            :class="errors.name ? 'input-error' : ''"
            :aria-invalid="Boolean(errors.name)"
            placeholder="Acme Store"
          />
          <p v-if="errors.name" class="error-text">{{ errors.name }}</p>
        </div>
        <div>
          <label for="m-email" class="label">Email</label>
          <input
            id="m-email"
            v-model="form.email"
            type="email"
            class="input"
            :class="errors.email ? 'input-error' : ''"
            placeholder="billing@acme.com"
          />
          <p v-if="errors.email" class="error-text">{{ errors.email }}</p>
        </div>
        <div>
          <label for="m-webhook" class="label">Webhook URL</label>
          <input
            id="m-webhook"
            v-model="form.webhook_url"
            type="url"
            class="input mono"
            :class="errors.webhook_url ? 'input-error' : ''"
            placeholder="https://acme.com/webhooks/cryptopay"
          />
          <p v-if="errors.webhook_url" class="error-text">{{ errors.webhook_url }}</p>
          <p v-else class="hint">Leave empty to disable outgoing webhooks.</p>
        </div>
        <label class="flex cursor-pointer items-center gap-2.5">
          <input
            v-model="form.is_active"
            type="checkbox"
            class="checkbox"
          />
          <span class="text-sm">Active</span>
        </label>
      </form>
      <template #footer>
        <button type="button" class="btn-secondary" @click="createOpen = false">Cancel</button>
        <button type="submit" form="service-form" class="btn-primary" :disabled="saving">
          <Spinner v-if="saving" :size="14" />
          Create service
        </button>
      </template>
    </Modal>
  </div>
</template>
