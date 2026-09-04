<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Coins, Save, Trash2, Users } from 'lucide-vue-next'
import AmountDisplay from '@/components/AmountDisplay.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import ProgressBar from '@/components/ProgressBar.vue'
import Skeleton from '@/components/Skeleton.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TabNav from '@/components/TabNav.vue'
import type { TabItem } from '@/components/tabs'
import type { Column } from '@/components/table'
import { tokensApi, type TokenPayload } from '@/api/tokens'
import { isApiError } from '@/api/http'
import type { PaginationMeta, Token, TokenHolding, TokenPurchase } from '@/api/types'
import { fieldErrors, reportError } from '@/composables/useErrorHandler'
import { formatDateTime, percentOf, truncateMiddle } from '@/utils/format'
import { toast } from '@/utils/toast'
import { safeImageUrl } from '@/utils/url'

const props = defineProps<{ id: string }>()
const router = useRouter()

const token = ref<Token | null>(null)
const loading = ref(true)
const notFound = ref(false)
const activeTab = ref('overview')

const tabs = computed<TabItem[]>(() => [
  { key: 'overview', label: 'Overview' },
  { key: 'purchases', label: 'Purchases', count: purchasesMeta.value.total },
  { key: 'holdings', label: 'Holdings', count: holdings.value.length },
])

/** Token art is an operator-supplied remote URL — only plain http(s) is rendered. */
const imageUrl = computed(() => safeImageUrl(token.value?.image_url))

const soldPercent = computed(() =>
  token.value?.total_supply ? percentOf(token.value.sold, token.value.total_supply) : 0,
)

/* ------------------------------------------------------------------ form */
const form = reactive<Partial<TokenPayload>>({})
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const deleteOpen = ref(false)
const deleting = ref(false)

function syncForm(source: Token): void {
  Object.assign(form, {
    symbol: source.symbol,
    name: source.name,
    description: source.description ?? '',
    price_usd: source.price_usd,
    decimals: source.decimals,
    total_supply: source.total_supply ?? '',
    min_purchase: source.min_purchase ?? '',
    max_purchase: source.max_purchase ?? '',
    is_active: source.is_active,
    image_url: source.image_url ?? '',
  })
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    token.value = await tokensApi.update(props.id, {
      symbol: form.symbol?.trim().toUpperCase(),
      name: form.name?.trim(),
      description: form.description?.trim() || null,
      price_usd: form.price_usd?.trim(),
      decimals: Number(form.decimals ?? 18),
      total_supply: form.total_supply?.trim() || null,
      min_purchase: form.min_purchase?.trim() || null,
      max_purchase: form.max_purchase?.trim() || null,
      is_active: form.is_active,
      image_url: form.image_url?.trim() || null,
    })
    if (token.value) syncForm(token.value)
    toast.success('Token updated')
  } catch (error) {
    errors.value = fieldErrors(error)
    reportError(error, 'Could not update the token')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  deleting.value = true
  try {
    await tokensApi.remove(props.id)
    toast.success('Token deleted')
    void router.push({ name: 'tokens' })
  } catch (error) {
    reportError(error, 'Could not delete the token')
  } finally {
    deleting.value = false
    deleteOpen.value = false
  }
}

/* ------------------------------------------------------- purchases + holdings */
const purchases = ref<TokenPurchase[]>([])
const purchasesMeta = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const purchasesLoading = ref(false)

const holdings = ref<TokenHolding[]>([])
const holdingsLoading = ref(false)

const purchaseColumns: Column[] = [
  { key: 'customer_id', label: 'Customer' },
  { key: 'token_amount', label: 'Tokens', class: 'text-right' },
  { key: 'pay_amount', label: 'Paid', class: 'text-right' },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Created', class: 'text-right', hideBelow: 'md' },
]

const holdingColumns: Column[] = [
  { key: 'customer_id', label: 'Customer' },
  { key: 'amount', label: 'Balance', class: 'text-right' },
  { key: 'updated_at', label: 'Updated', class: 'text-right', hideBelow: 'sm' },
]

async function loadPurchases(page = 1): Promise<void> {
  purchasesLoading.value = true
  try {
    const response = await tokensApi.purchases({ token_id: props.id, page, per_page: 15 })
    purchases.value = response.data ?? []
    purchasesMeta.value = response.meta ?? purchasesMeta.value
  } catch (error) {
    reportError(error, 'Could not load purchases')
  } finally {
    purchasesLoading.value = false
  }
}

async function loadHoldings(): Promise<void> {
  holdingsLoading.value = true
  try {
    const response = await tokensApi.holdings(props.id, { per_page: 100 })
    holdings.value = Array.isArray(response) ? response : (response.data ?? [])
  } catch (error) {
    reportError(error, 'Could not load holdings')
  } finally {
    holdingsLoading.value = false
  }
}

async function loadToken(): Promise<void> {
  loading.value = true
  try {
    token.value = await tokensApi.get(props.id)
    syncForm(token.value)
    notFound.value = false
  } catch (error) {
    if (isApiError(error) && error.status === 404) notFound.value = true
    else reportError(error, 'Failed to load the token')
  } finally {
    loading.value = false
  }
}

watch(activeTab, (tab) => {
  if (tab === 'purchases' && purchases.value.length === 0 && !purchasesLoading.value) void loadPurchases()
  if (tab === 'holdings' && holdings.value.length === 0 && !holdingsLoading.value) void loadHoldings()
})

onMounted(async () => {
  await loadToken()
  // Both counts feed the tab badges, so load them up front — a lazily loaded
  // Holdings tab renders a "0" badge that wrongly reads as "no holders".
  void loadPurchases()
  void loadHoldings()
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader :title="token ? `${token.symbol} — ${token.name}` : 'Token'">
      <template #breadcrumb>
        <RouterLink
          to="/admin/tokens"
          class="inline-flex items-center gap-1.5 text-xs text-muted transition-colors hover:text-text"
        >
          <ArrowLeft :size="13" aria-hidden="true" />
          Tokens
        </RouterLink>
      </template>
      <template #actions>
        <StatusBadge v-if="token" :status="token.is_active ? 'active' : 'inactive'" />
        <button type="button" class="btn-danger btn-sm" @click="deleteOpen = true">
          <Trash2 :size="14" aria-hidden="true" />
          Delete
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-4">
      <Skeleton height="h-10" rounded="rounded-xl" />
      <Skeleton height="h-72" rounded="rounded-2xl" />
    </div>

    <EmptyState
      v-else-if="notFound || !token"
      :icon="Coins"
      title="Token not found"
      description="This token does not exist or was removed."
    >
      <RouterLink to="/admin/tokens" class="btn-secondary">Back to tokens</RouterLink>
    </EmptyState>

    <template v-else>
      <TabNav v-model="activeTab" :tabs="tabs" />

      <!-- OVERVIEW -->
      <div
        v-show="activeTab === 'overview'"
        id="panel-overview"
        role="tabpanel"
        aria-labelledby="tab-overview"
        class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3"
      >
        <section class="card p-5">
          <div class="flex items-center gap-3">
            <img
              v-if="imageUrl"
              :src="imageUrl"
              alt=""
              class="h-12 w-12 rounded-full border border-border object-cover"
              referrerpolicy="no-referrer"
            />
            <span
              v-else
              class="flex h-12 w-12 items-center justify-center rounded-full border border-border bg-surface-2 text-sm font-semibold text-primary-hover"
              aria-hidden="true"
            >
              {{ token.symbol.slice(0, 3) }}
            </span>
            <div class="min-w-0">
              <p class="truncate text-base font-semibold">{{ token.symbol }}</p>
              <p class="truncate text-xs text-muted">{{ token.name }}</p>
            </div>
          </div>

          <dl class="mt-5 space-y-3.5 text-sm">
            <div class="flex items-baseline justify-between gap-3">
              <dt class="text-xs text-muted">Price</dt>
              <dd><AmountDisplay :value="token.price_usd" currency="USD" size="sm" /></dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
              <dt class="text-xs text-muted">Sold</dt>
              <dd><AmountDisplay :value="token.sold" size="sm" /></dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
              <dt class="text-xs text-muted">Total supply</dt>
              <dd>
                <AmountDisplay v-if="token.total_supply" :value="token.total_supply" size="sm" muted />
                <span v-else class="text-xs text-muted">Unlimited</span>
              </dd>
            </div>
            <div v-if="token.total_supply" class="space-y-1.5 pt-1">
              <ProgressBar :value="soldPercent" height="sm" label="Supply sold" />
              <p class="text-[11px] text-muted">{{ soldPercent.toFixed(1) }}% of supply sold</p>
            </div>
            <div class="flex items-baseline justify-between gap-3 border-t border-border pt-3.5">
              <dt class="text-xs text-muted">Service</dt>
              <dd class="min-w-0 truncate">
                <RouterLink
                  v-if="token.merchant"
                  :to="{ name: 'service-detail', params: { id: token.merchant.id } }"
                  class="link text-sm"
                >
                  {{ token.merchant.name }}
                </RouterLink>
                <span v-else class="text-sm text-muted">—</span>
              </dd>
            </div>
          </dl>
        </section>

        <section class="card p-5 lg:col-span-2">
          <h2 class="text-sm font-semibold">Edit token</h2>
          <form class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <div>
              <label for="e-symbol" class="label">Symbol</label>
              <input id="e-symbol" v-model="form.symbol" type="text" class="input mono uppercase"
                :class="errors.symbol ? 'input-error' : ''" />
              <p v-if="errors.symbol" class="error-text">{{ errors.symbol }}</p>
            </div>
            <div>
              <label for="e-name" class="label">Name</label>
              <input id="e-name" v-model="form.name" type="text" class="input"
                :class="errors.name ? 'input-error' : ''" />
              <p v-if="errors.name" class="error-text">{{ errors.name }}</p>
            </div>
            <div>
              <label for="e-price" class="label">Price in USD</label>
              <input id="e-price" v-model="form.price_usd" type="text" inputmode="decimal" class="input mono"
                :class="errors.price_usd ? 'input-error' : ''" />
              <p v-if="errors.price_usd" class="error-text">{{ errors.price_usd }}</p>
            </div>
            <div>
              <label for="e-decimals" class="label">Decimals</label>
              <input id="e-decimals" v-model.number="form.decimals" type="number" min="0" max="36" class="input mono" />
            </div>
            <div>
              <label for="e-supply" class="label">Total supply</label>
              <input id="e-supply" v-model="form.total_supply" type="text" inputmode="decimal" class="input mono"
                placeholder="Unlimited" />
            </div>
            <div>
              <label for="e-image" class="label">Image URL</label>
              <input id="e-image" v-model="form.image_url" type="url" class="input mono text-xs" />
            </div>
            <div>
              <label for="e-min" class="label">Min purchase</label>
              <input id="e-min" v-model="form.min_purchase" type="text" inputmode="decimal" class="input mono" />
            </div>
            <div>
              <label for="e-max" class="label">Max purchase</label>
              <input id="e-max" v-model="form.max_purchase" type="text" inputmode="decimal" class="input mono" />
            </div>
            <div class="sm:col-span-2">
              <label for="e-desc" class="label">Description</label>
              <textarea id="e-desc" v-model="form.description" rows="3" class="input resize-y" />
            </div>
            <label class="flex cursor-pointer items-center gap-2.5 sm:col-span-2">
              <input
                v-model="form.is_active"
                type="checkbox"
                class="checkbox"
              />
              <span class="text-sm">Active — available for purchase</span>
            </label>
            <div class="sm:col-span-2">
              <button type="submit" class="btn-primary btn-sm" :disabled="saving">
                <Spinner v-if="saving" :size="13" />
                <Save v-else :size="14" aria-hidden="true" />
                Save changes
              </button>
            </div>
          </form>
        </section>
      </div>

      <!-- PURCHASES -->
      <section
        v-show="activeTab === 'purchases'"
        id="panel-purchases"
        role="tabpanel"
        aria-labelledby="tab-purchases"
        class="card overflow-hidden"
      >
        <div class="border-b border-border px-5 py-4">
          <h2 class="text-sm font-semibold">Purchases</h2>
        </div>
        <DataTable
          :columns="purchaseColumns"
          :rows="purchases"
          :loading="purchasesLoading"
          caption="Token purchases"
        >
          <template #cell-customer_id="{ row }">
            <div class="min-w-0">
              <p class="mono truncate">{{ row.customer_id }}</p>
              <p v-if="row.customer_email" class="truncate text-xs text-muted">{{ row.customer_email }}</p>
            </div>
          </template>
          <template #cell-token_amount="{ row }">
            <AmountDisplay :value="row.token_amount" :currency="token?.symbol" size="sm" />
          </template>
          <template #cell-pay_amount="{ row }">
            <AmountDisplay :value="row.pay_amount" :currency="row.currency" size="sm" muted />
          </template>
          <template #cell-status="{ row }">
            <StatusBadge :status="row.status" size="sm" context="Purchase status" />
          </template>
          <template #cell-created_at="{ row }">
            <span class="whitespace-nowrap text-xs text-muted">{{ formatDateTime(row.created_at) }}</span>
          </template>
          <template #empty>
            <EmptyState :icon="Coins" title="No purchases" description="No one has bought this token yet." compact />
          </template>
        </DataTable>
        <Pagination :meta="purchasesMeta" :disabled="purchasesLoading" @change="loadPurchases" />
      </section>

      <!-- HOLDINGS -->
      <section
        v-show="activeTab === 'holdings'"
        id="panel-holdings"
        role="tabpanel"
        aria-labelledby="tab-holdings"
        class="card overflow-hidden"
      >
        <div class="border-b border-border px-5 py-4">
          <h2 class="text-sm font-semibold">Holdings</h2>
        </div>
        <DataTable
          :columns="holdingColumns"
          :rows="holdings"
          :loading="holdingsLoading"
          :row-key="(row, index) => row.customer_id ?? index"
          caption="Token holdings"
        >
          <template #cell-customer_id="{ row }">
            <span class="mono truncate">{{ truncateMiddle(row.customer_id, 14, 8) }}</span>
          </template>
          <template #cell-amount="{ row }">
            <AmountDisplay :value="row.amount" :currency="token?.symbol" size="sm" />
          </template>
          <template #cell-updated_at="{ row }">
            <span class="whitespace-nowrap text-xs text-muted">{{ formatDateTime(row.updated_at) }}</span>
          </template>
          <template #empty>
            <EmptyState :icon="Users" title="No holders" description="Holdings are credited when a purchase completes." compact />
          </template>
        </DataTable>
      </section>
    </template>

    <ConfirmDialog
      :open="deleteOpen"
      title="Delete this token?"
      message="The token product is removed from the catalog. Existing purchases and holdings are kept."
      confirm-label="Delete token"
      tone="danger"
      :loading="deleting"
      @close="deleteOpen = false"
      @confirm="remove"
    />
  </div>
</template>
