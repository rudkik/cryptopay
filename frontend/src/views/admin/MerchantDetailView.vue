<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, KeyRound, Plus, RefreshCw, RotateCw, Save, Trash2, Wallet } from 'lucide-vue-next'
import AmountDisplay from '@/components/AmountDisplay.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import Modal from '@/components/Modal.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import SecretReveal from '@/components/SecretReveal.vue'
import Skeleton from '@/components/Skeleton.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TabNav from '@/components/TabNav.vue'
import type { TabItem } from '@/components/tabs'
import type { Column } from '@/components/table'
import { merchantsApi } from '@/api/merchants'
import { invoicesApi } from '@/api/invoices'
import { isApiError } from '@/api/http'
import type { ApiKey, Invoice, Merchant, PaginationMeta } from '@/api/types'
import { fieldErrors, reportError } from '@/composables/useErrorHandler'
import { formatAmount, formatDate, formatDateTime, formatRelative, truncateMiddle } from '@/utils/format'
import { toast } from '@/utils/toast'

const props = defineProps<{ id: string }>()
const router = useRouter()

const merchant = ref<Merchant | null>(null)
const loading = ref(true)
const notFound = ref(false)
const activeTab = ref('overview')

const tabs = computed<TabItem[]>(() => [
  { key: 'overview', label: 'Overview' },
  { key: 'keys', label: 'API keys', count: activeKeys.value.length },
  { key: 'invoices', label: 'Invoices', count: invoicesMeta.value.total },
])

const balances = computed(() => merchant.value?.balances ?? [])
const apiKeys = computed(() => merchant.value?.api_keys ?? [])
const activeKeys = computed(() => apiKeys.value.filter((key) => !key.revoked_at))

/* ---------------------------------------------------------------- settings */
const settingsForm = reactive({ name: '', email: '', webhook_url: '', is_active: true })
const settingsErrors = ref<Record<string, string>>({})
const savingSettings = ref(false)

function syncForm(source: Merchant): void {
  settingsForm.name = source.name
  settingsForm.email = source.email ?? ''
  settingsForm.webhook_url = source.webhook_url ?? ''
  settingsForm.is_active = source.is_active
}

const settingsDirty = computed(() => {
  const source = merchant.value
  if (!source) return false
  return (
    settingsForm.name !== source.name ||
    settingsForm.email !== (source.email ?? '') ||
    settingsForm.webhook_url !== (source.webhook_url ?? '') ||
    settingsForm.is_active !== source.is_active
  )
})

async function saveSettings(): Promise<void> {
  savingSettings.value = true
  settingsErrors.value = {}
  try {
    merchant.value = await merchantsApi.update(props.id, {
      name: settingsForm.name.trim(),
      email: settingsForm.email.trim() || null,
      webhook_url: settingsForm.webhook_url.trim() || null,
      is_active: settingsForm.is_active,
    })
    if (merchant.value) syncForm(merchant.value)
    toast.success('Merchant updated')
  } catch (error) {
    settingsErrors.value = fieldErrors(error)
    reportError(error, 'Could not save the merchant')
  } finally {
    savingSettings.value = false
  }
}

/* ------------------------------------------------------------ secret rotate */
const rotateOpen = ref(false)
const rotating = ref(false)
const revealedSecret = ref<string | null>(null)

async function rotateSecret(): Promise<void> {
  rotating.value = true
  try {
    const response = await merchantsApi.rotateWebhookSecret(props.id)
    revealedSecret.value = response.webhook_secret
    rotateOpen.value = false
    toast.success('Webhook secret rotated', 'Existing signatures will stop validating.')
  } catch (error) {
    reportError(error, 'Could not rotate the webhook secret')
  } finally {
    rotating.value = false
  }
}

/* ---------------------------------------------------------------- api keys */
const keyModalOpen = ref(false)
const keyName = ref('')
const creatingKey = ref(false)
const keyErrors = ref<Record<string, string>>({})
const revealedKey = ref<string | null>(null)

const revokeTarget = ref<ApiKey | null>(null)
const revoking = ref(false)

const keyColumns: Column[] = [
  { key: 'name', label: 'Name' },
  { key: 'key_prefix', label: 'Prefix' },
  { key: 'last_used_at', label: 'Last used', hideBelow: 'sm' },
  { key: 'created_at', label: 'Created', hideBelow: 'md' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', class: 'text-right w-px' },
]

async function createKey(): Promise<void> {
  creatingKey.value = true
  keyErrors.value = {}
  try {
    const response = await merchantsApi.createApiKey(props.id, keyName.value.trim())
    revealedKey.value = response.key
    keyModalOpen.value = false
    keyName.value = ''
    await loadMerchant(true)
  } catch (error) {
    keyErrors.value = fieldErrors(error)
    reportError(error, 'Could not create the API key')
  } finally {
    creatingKey.value = false
  }
}

async function revokeKey(): Promise<void> {
  if (!revokeTarget.value) return
  revoking.value = true
  try {
    await merchantsApi.revokeApiKey(props.id, revokeTarget.value.id)
    toast.success('API key revoked')
    revokeTarget.value = null
    await loadMerchant(true)
  } catch (error) {
    reportError(error, 'Could not revoke the API key')
  } finally {
    revoking.value = false
  }
}

/* --------------------------------------------------------------- invoices */
const invoices = ref<Invoice[]>([])
const invoicesMeta = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const invoicesLoading = ref(false)

const invoiceColumns: Column[] = [
  { key: 'id', label: 'Invoice' },
  { key: 'amount', label: 'Amount', class: 'text-right' },
  { key: 'network', label: 'Network', hideBelow: 'sm' },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Created', class: 'text-right', hideBelow: 'md' },
]

async function loadInvoices(page = 1): Promise<void> {
  invoicesLoading.value = true
  try {
    const response = await invoicesApi.list({ merchant_id: props.id, page, per_page: 15 })
    invoices.value = response.data ?? []
    invoicesMeta.value = response.meta ?? invoicesMeta.value
  } catch (error) {
    reportError(error, 'Could not load the merchant invoices')
  } finally {
    invoicesLoading.value = false
  }
}

/* ------------------------------------------------------------------- load */
async function loadMerchant(silent = false): Promise<void> {
  if (!silent) loading.value = true
  try {
    merchant.value = await merchantsApi.get(props.id)
    syncForm(merchant.value)
    notFound.value = false
  } catch (error) {
    if (isApiError(error) && error.status === 404) notFound.value = true
    else reportError(error, 'Failed to load the merchant')
  } finally {
    loading.value = false
  }
}

watch(activeTab, (tab) => {
  if (tab === 'invoices' && invoices.value.length === 0 && !invoicesLoading.value) void loadInvoices()
})

onMounted(async () => {
  await loadMerchant()
  void loadInvoices()
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader :title="merchant?.name ?? 'Merchant'">
      <template #breadcrumb>
        <RouterLink
          to="/admin/merchants"
          class="inline-flex items-center gap-1.5 text-xs text-muted transition-colors hover:text-text"
        >
          <ArrowLeft :size="13" aria-hidden="true" />
          Merchants
        </RouterLink>
      </template>
      <template #actions>
        <StatusBadge v-if="merchant" :status="merchant.is_active ? 'active' : 'inactive'" />
        <button type="button" class="btn-secondary btn-sm" @click="loadMerchant(true)">
          <RefreshCw :size="14" aria-hidden="true" />
          Refresh
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-4">
      <Skeleton height="h-10" rounded="rounded-xl" />
      <Skeleton height="h-56" rounded="rounded-2xl" />
    </div>

    <EmptyState
      v-else-if="notFound || !merchant"
      title="Merchant not found"
      description="This merchant does not exist or was removed."
    >
      <RouterLink to="/admin/merchants" class="btn-secondary">Back to merchants</RouterLink>
    </EmptyState>

    <template v-else>
      <TabNav v-model="activeTab" :tabs="tabs" />

      <!-- OVERVIEW -->
      <div
        v-show="activeTab === 'overview'"
        id="panel-overview"
        role="tabpanel"
        aria-labelledby="tab-overview"
        class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2"
      >
        <section class="card p-5">
          <h2 class="text-sm font-semibold">Balances</h2>
          <p class="mt-0.5 text-xs text-muted">Credited only on confirmed transactions.</p>

          <div v-if="balances.length" class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border text-xs uppercase tracking-wide text-muted">
                  <th scope="col" class="py-2 text-left font-semibold">Currency</th>
                  <th scope="col" class="py-2 text-left font-semibold">Network</th>
                  <th scope="col" class="py-2 text-right font-semibold">Available</th>
                  <th scope="col" class="py-2 text-right font-semibold">Pending</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="balance in balances"
                  :key="`${balance.currency}-${balance.network}`"
                  class="border-b border-border/60 last:border-0"
                >
                  <td class="py-2.5 font-medium">{{ balance.currency }}</td>
                  <td class="py-2.5">
                    <NetworkBadge :network="balance.network" size="sm" compact />
                  </td>
                  <td class="py-2.5 text-right">
                    <AmountDisplay :value="balance.available" size="sm" />
                  </td>
                  <td class="py-2.5 text-right">
                    <AmountDisplay :value="balance.pending" size="sm" muted />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <EmptyState
            v-else
            :icon="Wallet"
            title="No balances"
            description="Balances appear once a confirmed deposit is credited."
            compact
          />
        </section>

        <section class="card p-5">
          <h2 class="text-sm font-semibold">Settings</h2>
          <p class="mt-0.5 text-xs text-muted">Webhook endpoint and account status.</p>

          <form class="mt-4 space-y-4" novalidate @submit.prevent="saveSettings">
            <div>
              <label for="s-name" class="label">Name</label>
              <input
                id="s-name"
                v-model="settingsForm.name"
                type="text"
                class="input"
                :class="settingsErrors.name ? 'input-error' : ''"
              />
              <p v-if="settingsErrors.name" class="error-text">{{ settingsErrors.name }}</p>
            </div>
            <div>
              <label for="s-email" class="label">Email</label>
              <input
                id="s-email"
                v-model="settingsForm.email"
                type="email"
                class="input"
                :class="settingsErrors.email ? 'input-error' : ''"
              />
              <p v-if="settingsErrors.email" class="error-text">{{ settingsErrors.email }}</p>
            </div>
            <div>
              <label for="s-webhook" class="label">Webhook URL</label>
              <input
                id="s-webhook"
                v-model="settingsForm.webhook_url"
                type="url"
                class="input mono"
                :class="settingsErrors.webhook_url ? 'input-error' : ''"
                placeholder="https://example.com/webhooks/cryptopay"
              />
              <p v-if="settingsErrors.webhook_url" class="error-text">{{ settingsErrors.webhook_url }}</p>
              <p v-else class="hint">Events are signed with the merchant webhook secret.</p>
            </div>
            <label class="flex cursor-pointer items-center gap-2.5">
              <input
                v-model="settingsForm.is_active"
                type="checkbox"
                class="checkbox"
              />
              <span class="text-sm">Active</span>
            </label>

            <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
              <button type="submit" class="btn-primary btn-sm" :disabled="savingSettings || !settingsDirty">
                <Spinner v-if="savingSettings" :size="13" />
                <Save v-else :size="14" aria-hidden="true" />
                Save changes
              </button>
              <button type="button" class="btn-secondary btn-sm" @click="rotateOpen = true">
                <RotateCw :size="14" aria-hidden="true" />
                Rotate webhook secret
              </button>
            </div>
          </form>

          <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-border pt-4 text-xs">
            <div class="min-w-0">
              <dt class="text-muted">Merchant ID</dt>
              <dd class="mono mt-0.5 truncate">{{ merchant.id }}</dd>
            </div>
            <div class="min-w-0 text-right">
              <dt class="text-muted">Created</dt>
              <dd class="mt-0.5">{{ formatDate(merchant.created_at) }}</dd>
            </div>
            <div v-if="merchant.settings?.underpayment_tolerance !== undefined" class="min-w-0">
              <dt class="text-muted">Underpayment tolerance</dt>
              <dd class="mono mt-0.5">
                {{ formatAmount(String(merchant.settings.underpayment_tolerance)) }}%
              </dd>
            </div>
          </dl>
        </section>
      </div>

      <!-- API KEYS -->
      <section
        v-show="activeTab === 'keys'"
        id="panel-keys"
        role="tabpanel"
        aria-labelledby="tab-keys"
        class="card overflow-hidden"
      >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
          <div>
            <h2 class="text-sm font-semibold">API keys</h2>
            <p class="mt-0.5 text-xs text-muted">
              Keys are shown once at creation and stored as a SHA-256 hash.
            </p>
          </div>
          <button type="button" class="btn-primary btn-sm" @click="keyModalOpen = true">
            <Plus :size="14" aria-hidden="true" />
            New key
          </button>
        </div>

        <DataTable :columns="keyColumns" :rows="apiKeys" caption="API keys">
          <template #cell-name="{ row }">
            <span class="font-medium">{{ row.name }}</span>
          </template>
          <template #cell-key_prefix="{ row }">
            <span class="mono text-muted">{{ row.key_prefix }}…</span>
          </template>
          <template #cell-last_used_at="{ row }">
            <span class="text-xs text-muted">
              {{ row.last_used_at ? formatRelative(row.last_used_at) : 'Never' }}
            </span>
          </template>
          <template #cell-created_at="{ row }">
            <span class="text-xs text-muted">{{ formatDate(row.created_at) }}</span>
          </template>
          <template #cell-status="{ row }">
            <StatusBadge :status="row.revoked_at ? 'revoked' : 'active'" size="sm" />
          </template>
          <template #cell-actions="{ row }">
            <button
              v-if="!row.revoked_at"
              type="button"
              class="btn-ghost btn-sm hover:text-danger"
              :aria-label="`Revoke key ${row.name}`"
              @click="revokeTarget = row"
            >
              <Trash2 :size="13" aria-hidden="true" />
              Revoke
            </button>
          </template>
          <template #empty>
            <EmptyState
              :icon="KeyRound"
              title="No API keys"
              description="Create a key so this merchant can call the v1 API."
              compact
            >
              <button type="button" class="btn-primary btn-sm" @click="keyModalOpen = true">
                <Plus :size="14" aria-hidden="true" />
                New key
              </button>
            </EmptyState>
          </template>
        </DataTable>
      </section>

      <!-- INVOICES -->
      <section
        v-show="activeTab === 'invoices'"
        id="panel-invoices"
        role="tabpanel"
        aria-labelledby="tab-invoices"
        class="card overflow-hidden"
      >
        <div class="border-b border-border px-5 py-4">
          <h2 class="text-sm font-semibold">Invoices</h2>
        </div>
        <DataTable
          :columns="invoiceColumns"
          :rows="invoices"
          :loading="invoicesLoading"
          clickable
          caption="Merchant invoices"
          @row-click="(row) => router.push({ name: 'invoice-detail', params: { id: row.id } })"
        >
          <template #cell-id="{ row }">
            <div class="min-w-0">
              <p class="mono truncate">{{ truncateMiddle(row.id, 8, 6) }}</p>
              <p v-if="row.external_id" class="truncate text-xs text-muted">{{ row.external_id }}</p>
            </div>
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
            <span class="whitespace-nowrap text-xs text-muted">{{ formatDateTime(row.created_at) }}</span>
          </template>
          <template #empty>
            <EmptyState title="No invoices" description="This merchant has not created any invoice yet." compact />
          </template>
        </DataTable>
        <Pagination :meta="invoicesMeta" :disabled="invoicesLoading" @change="loadInvoices" />
      </section>
    </template>

    <!-- Create API key -->
    <Modal
      :open="keyModalOpen"
      title="Create API key"
      description="The full key is displayed only once."
      size="sm"
      @close="keyModalOpen = false"
    >
      <form id="key-form" novalidate @submit.prevent="createKey">
        <label for="k-name" class="label">Key name</label>
        <input
          id="k-name"
          v-model="keyName"
          type="text"
          required
          data-autofocus
          class="input"
          :class="keyErrors.name ? 'input-error' : ''"
          placeholder="Production server"
        />
        <p v-if="keyErrors.name" class="error-text">{{ keyErrors.name }}</p>
        <p v-else class="hint">A label to help you identify where the key is used.</p>
      </form>
      <template #footer>
        <button type="button" class="btn-secondary" @click="keyModalOpen = false">Cancel</button>
        <button type="submit" form="key-form" class="btn-primary" :disabled="creatingKey || !keyName.trim()">
          <Spinner v-if="creatingKey" :size="14" />
          Create key
        </button>
      </template>
    </Modal>

    <SecretReveal
      :open="revealedKey !== null"
      title="API key created"
      description="Copy this key now — it is hashed on the server and cannot be shown again."
      :secret="revealedKey ?? ''"
      secret-label="API key"
      @close="revealedKey = null"
    />

    <SecretReveal
      :open="revealedSecret !== null"
      title="Webhook secret rotated"
      description="Update your endpoint with this secret. Signatures made with the previous secret will no longer validate."
      :secret="revealedSecret ?? ''"
      secret-label="Webhook secret"
      @close="revealedSecret = null"
    />

    <ConfirmDialog
      :open="rotateOpen"
      title="Rotate webhook secret?"
      message="A new secret is generated immediately. Deliveries signed with the old secret will fail verification until you update your endpoint."
      confirm-label="Rotate secret"
      tone="danger"
      :loading="rotating"
      @close="rotateOpen = false"
      @confirm="rotateSecret"
    />

    <ConfirmDialog
      :open="revokeTarget !== null"
      title="Revoke this API key?"
      :message="`“${revokeTarget?.name ?? ''}” will stop working immediately. This cannot be undone.`"
      confirm-label="Revoke key"
      tone="danger"
      :loading="revoking"
      @close="revokeTarget = null"
      @confirm="revokeKey"
    />
  </div>
</template>
