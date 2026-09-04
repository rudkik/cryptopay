<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Coins, Plus } from 'lucide-vue-next'
import AmountDisplay from '@/components/AmountDisplay.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import FilterBar from '@/components/FilterBar.vue'
import Modal from '@/components/Modal.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import SelectFilter from '@/components/SelectFilter.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
import { tokensApi, type TokenPayload } from '@/api/tokens'
import { merchantsApi } from '@/api/merchants'
import type { Token } from '@/api/types'
import { usePaginatedList } from '@/composables/usePaginatedList'
import { fieldErrors, reportError } from '@/composables/useErrorHandler'
import { toast } from '@/utils/toast'
import type { Option } from '@/utils/options'
import { safeImageUrl } from '@/utils/url'

const router = useRouter()

const { filters, items, meta, loading, hasFilters, load, setPage, resetFilters } = usePaginatedList<
  Token,
  Record<'q' | 'merchant_id', string>
>({
  defaultFilters: { q: '', merchant_id: '' },
  fetcher: (params) => tokensApi.list(params),
  perPage: 25,
})

const serviceOptions = ref<Option[]>([])

/** Token art is an operator-supplied remote URL — only plain http(s) is rendered. */
const tokenImage = safeImageUrl

const columns: Column[] = [
  { key: 'symbol', label: 'Token' },
  { key: 'merchant', label: 'Service', hideBelow: 'md' },
  { key: 'price_usd', label: 'Price (USD)', class: 'text-right' },
  { key: 'sold', label: 'Sold', class: 'text-right', hideBelow: 'sm' },
  { key: 'total_supply', label: 'Supply', class: 'text-right', hideBelow: 'lg' },
  { key: 'is_active', label: 'Status' },
]

const createOpen = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const form = reactive<TokenPayload>({
  merchant_id: '',
  symbol: '',
  name: '',
  description: '',
  price_usd: '',
  decimals: 18,
  total_supply: '',
  min_purchase: '',
  max_purchase: '',
  is_active: true,
  image_url: '',
})

function openCreate(): void {
  Object.assign(form, {
    merchant_id: serviceOptions.value[0]?.value ?? '',
    symbol: '',
    name: '',
    description: '',
    price_usd: '',
    decimals: 18,
    total_supply: '',
    min_purchase: '',
    max_purchase: '',
    is_active: true,
    image_url: '',
  })
  errors.value = {}
  createOpen.value = true
}

async function submit(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const token = await tokensApi.create({
      merchant_id: form.merchant_id,
      symbol: form.symbol.trim().toUpperCase(),
      name: form.name.trim(),
      description: form.description?.trim() || null,
      price_usd: form.price_usd.trim(),
      decimals: Number(form.decimals ?? 18),
      total_supply: form.total_supply?.trim() || null,
      min_purchase: form.min_purchase?.trim() || null,
      max_purchase: form.max_purchase?.trim() || null,
      is_active: form.is_active,
      image_url: form.image_url?.trim() || null,
    })
    toast.success('Token created', token.symbol)
    createOpen.value = false
    void router.push({ name: 'token-detail', params: { id: token.id } })
  } catch (error) {
    errors.value = fieldErrors(error)
    reportError(error, 'Could not create the token')
  } finally {
    saving.value = false
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
    <PageHeader title="Tokens" description="Token-sale products purchasable with USDT and USDC.">
      <template #actions>
        <button type="button" class="btn-primary" @click="openCreate">
          <Plus :size="15" aria-hidden="true" />
          New token
        </button>
      </template>
    </PageHeader>

    <section class="card overflow-hidden">
      <FilterBar
        v-model:search="filters.q"
        search-label="Search tokens"
        :has-filters="hasFilters"
        @reset="resetFilters"
      >
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
        caption="Tokens"
        @row-click="(row) => router.push({ name: 'token-detail', params: { id: row.id } })"
      >
        <template #cell-symbol="{ row }">
          <div class="flex min-w-0 items-center gap-3">
            <img
              v-if="tokenImage(row.image_url)"
              :src="tokenImage(row.image_url)!"
              :alt="''"
              class="h-8 w-8 shrink-0 rounded-full border border-border object-cover"
              loading="lazy"
              referrerpolicy="no-referrer"
            />
            <span
              v-else
              class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-border bg-surface-2 text-[11px] font-semibold text-primary-hover"
              aria-hidden="true"
            >
              {{ row.symbol.slice(0, 3) }}
            </span>
            <div class="min-w-0">
              <p class="truncate font-medium">{{ row.symbol }}</p>
              <p class="truncate text-xs text-muted">{{ row.name }}</p>
            </div>
          </div>
        </template>
        <template #cell-merchant="{ row }">
          <span class="truncate text-muted">{{ row.merchant?.name ?? '—' }}</span>
        </template>
        <template #cell-price_usd="{ row }">
          <AmountDisplay :value="row.price_usd" currency="USD" size="sm" />
        </template>
        <template #cell-sold="{ row }">
          <AmountDisplay :value="row.sold" size="sm" muted />
        </template>
        <template #cell-total_supply="{ row }">
          <AmountDisplay v-if="row.total_supply" :value="row.total_supply" size="sm" muted />
          <span v-else class="text-xs text-muted">Unlimited</span>
        </template>
        <template #cell-is_active="{ row }">
          <StatusBadge :status="row.is_active ? 'active' : 'inactive'" size="sm" />
        </template>
        <template #empty>
          <EmptyState
            :icon="Coins"
            title="No tokens yet"
            description="Create a token product to start selling it for USDT or USDC."
          >
            <button type="button" class="btn-primary" @click="openCreate">
              <Plus :size="15" aria-hidden="true" />
              New token
            </button>
          </EmptyState>
        </template>
      </DataTable>

      <Pagination :meta="meta" :disabled="loading" @change="setPage" />
    </section>

    <Modal :open="createOpen" title="New token" size="lg" @close="createOpen = false">
      <form id="token-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
        <div class="sm:col-span-2">
          <label for="t-service" class="label">Service <span class="text-danger">*</span></label>
          <select
            id="t-service"
            v-model="form.merchant_id"
            required
            data-autofocus
            class="input"
            :class="errors.merchant_id ? 'input-error' : ''"
          >
            <option value="" disabled>Select a service</option>
            <option v-for="option in serviceOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <p v-if="errors.merchant_id" class="error-text">{{ errors.merchant_id }}</p>
        </div>

        <div>
          <label for="t-symbol" class="label">Symbol <span class="text-danger">*</span></label>
          <input
            id="t-symbol"
            v-model="form.symbol"
            type="text"
            required
            maxlength="16"
            class="input mono uppercase"
            :class="errors.symbol ? 'input-error' : ''"
            placeholder="ACME"
          />
          <p v-if="errors.symbol" class="error-text">{{ errors.symbol }}</p>
        </div>

        <div>
          <label for="t-name" class="label">Name <span class="text-danger">*</span></label>
          <input
            id="t-name"
            v-model="form.name"
            type="text"
            required
            class="input"
            :class="errors.name ? 'input-error' : ''"
            placeholder="Acme Token"
          />
          <p v-if="errors.name" class="error-text">{{ errors.name }}</p>
        </div>

        <div>
          <label for="t-price" class="label">Price in USD <span class="text-danger">*</span></label>
          <input
            id="t-price"
            v-model="form.price_usd"
            type="text"
            inputmode="decimal"
            required
            class="input mono"
            :class="errors.price_usd ? 'input-error' : ''"
            placeholder="0.25"
          />
          <p v-if="errors.price_usd" class="error-text">{{ errors.price_usd }}</p>
        </div>

        <div>
          <label for="t-decimals" class="label">Decimals</label>
          <input id="t-decimals" v-model.number="form.decimals" type="number" min="0" max="36" class="input mono" />
        </div>

        <div>
          <label for="t-supply" class="label">Total supply</label>
          <input id="t-supply" v-model="form.total_supply" type="text" inputmode="decimal" class="input mono" placeholder="Unlimited" />
        </div>

        <div>
          <label for="t-image" class="label">Image URL</label>
          <input id="t-image" v-model="form.image_url" type="url" class="input mono text-xs" placeholder="https://…" />
        </div>

        <div>
          <label for="t-min" class="label">Min purchase</label>
          <input id="t-min" v-model="form.min_purchase" type="text" inputmode="decimal" class="input mono" />
        </div>

        <div>
          <label for="t-max" class="label">Max purchase</label>
          <input id="t-max" v-model="form.max_purchase" type="text" inputmode="decimal" class="input mono" />
        </div>

        <div class="sm:col-span-2">
          <label for="t-desc" class="label">Description</label>
          <textarea id="t-desc" v-model="form.description" rows="3" class="input resize-y" />
        </div>

        <label class="flex cursor-pointer items-center gap-2.5 sm:col-span-2">
          <input
            v-model="form.is_active"
            type="checkbox"
            class="checkbox"
          />
          <span class="text-sm">Active — available for purchase</span>
        </label>
      </form>
      <template #footer>
        <button type="button" class="btn-secondary" @click="createOpen = false">Cancel</button>
        <button type="submit" form="token-form" class="btn-primary" :disabled="saving">
          <Spinner v-if="saving" :size="14" />
          Create token
        </button>
      </template>
    </Modal>
  </div>
</template>
