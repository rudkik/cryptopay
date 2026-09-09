<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ListChecks, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-vue-next'
import AddressDisplay from '@/components/AddressDisplay.vue'
import AmountDisplay from '@/components/AmountDisplay.vue'
import CoinLogo from '@/components/CoinLogo.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import Modal from '@/components/Modal.vue'
import NetworkBadge from '@/components/NetworkBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import SelectFilter from '@/components/SelectFilter.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
import { networksApi } from '@/api/networks'
import { receivingAddressesApi, type ReceivingAddressPayload } from '@/api/receivingAddresses'
import type { Currency, Network, NetworkCode, ReceivingAddress } from '@/api/types'
import { fieldErrors, reportError } from '@/composables/useErrorHandler'
import { useAuthStore } from '@/stores/auth'
import { formatRelative, trimAmount, truncateMiddle } from '@/utils/format'
import { NETWORK_OPTIONS } from '@/utils/options'
import { toast } from '@/utils/toast'

const auth = useAuthStore()

/* ---------------------------------------------------------------- listing */

const rows = ref<ReceivingAddress[]>([])
const loading = ref(true)
const refreshing = ref(false)
const networkFilter = ref<string>('')

/** Enabled token contracts per network, so the form only offers real pairs. */
const networks = ref<Network[]>([])

const filteredRows = computed(() =>
  networkFilter.value ? rows.value.filter((r) => r.network === networkFilter.value) : rows.value,
)

const columns: Column[] = [
  { key: 'address', label: 'Address' },
  { key: 'network', label: 'Network' },
  { key: 'currencies', label: 'Accepts' },
  { key: 'status', label: 'Status' },
  { key: 'received', label: 'Received', class: 'text-right', hideBelow: 'md' },
  { key: 'priority', label: 'Priority', class: 'text-right', hideBelow: 'lg' },
  { key: 'actions', label: '', class: 'text-right w-px' },
]

function currenciesFor(network: NetworkCode | ''): Currency[] {
  const found = networks.value.find((n) => n.code === network)
  const contracts = found?.token_contracts ?? found?.tokens ?? []
  return contracts.filter((c) => c.is_enabled !== false).map((c) => c.symbol)
}

function nonZeroReceived(received: Record<string, string> | null | undefined): [string, string][] {
  return Object.entries(received ?? {}).filter(([, amount]) => trimAmount(amount) !== '0')
}

async function load(silent = false): Promise<void> {
  if (silent) refreshing.value = true
  else loading.value = true
  try {
    const [list, nets] = await Promise.all([receivingAddressesApi.list(), networksApi.list()])
    rows.value = list
    networks.value = Array.isArray(nets) ? nets : (nets.data ?? [])
  } catch (error) {
    reportError(error, 'Failed to load the receiving addresses')
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

/* ------------------------------------------------------------ create/edit */

const modalOpen = ref(false)
const editing = ref<ReceivingAddress | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const form = reactive<ReceivingAddressPayload>({
  network: 'tron',
  address: '',
  currencies: [],
  label: '',
  priority: 100,
  is_enabled: true,
})

const availableCurrencies = computed(() => currenciesFor(form.network))

/** "All" is the empty list on the API; the form shows it as a checkbox. */
const acceptsAll = computed({
  get: () => form.currencies.length === 0,
  set: (all: boolean) => {
    form.currencies = all ? [] : [...availableCurrencies.value]
  },
})

function toggleCurrency(symbol: Currency, on: boolean): void {
  const next = new Set(form.currencies)
  if (on) next.add(symbol)
  else next.delete(symbol)
  form.currencies = availableCurrencies.value.filter((c) => next.has(c))
}

function onNetworkChange(): void {
  // Currencies are per network: a USDC-only Tron address makes no sense on BSC.
  form.currencies = []
  delete errors.value.address
}

function openCreate(): void {
  editing.value = null
  Object.assign(form, {
    network: (networkFilter.value as NetworkCode) || 'tron',
    address: '',
    currencies: [],
    label: '',
    priority: 100,
    is_enabled: true,
  })
  errors.value = {}
  modalOpen.value = true
}

function openEdit(row: ReceivingAddress): void {
  editing.value = row
  Object.assign(form, {
    network: row.network,
    address: row.address,
    currencies: [...row.currencies],
    label: row.label ?? '',
    priority: row.priority,
    is_enabled: row.is_enabled,
  })
  errors.value = {}
  modalOpen.value = true
}

async function submit(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const shared = {
      currencies: form.currencies,
      label: form.label?.trim() || null,
      priority: Number(form.priority),
      is_enabled: form.is_enabled,
    }
    if (editing.value) {
      await receivingAddressesApi.update(editing.value.id, shared)
      toast.success('Address updated')
    } else {
      await receivingAddressesApi.create({
        network: form.network,
        address: form.address.trim(),
        ...shared,
      })
      toast.success('Address added')
    }
    modalOpen.value = false
    await load(true)
  } catch (error) {
    errors.value = fieldErrors(error)
    reportError(error, 'Could not save the address')
  } finally {
    saving.value = false
  }
}

async function toggleEnabled(row: ReceivingAddress): Promise<void> {
  try {
    await receivingAddressesApi.update(row.id, { is_enabled: !row.is_enabled })
    toast.success(row.is_enabled ? 'Address disabled' : 'Address enabled')
    await load(true)
  } catch (error) {
    reportError(error, 'Could not update the address')
  }
}

/* ----------------------------------------------------------------- delete */

const deleteTarget = ref<ReceivingAddress | null>(null)
const deleting = ref(false)

async function remove(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await receivingAddressesApi.remove(deleteTarget.value.id)
    toast.success('Address removed')
    deleteTarget.value = null
    await load(true)
  } catch (error) {
    reportError(error, 'Could not remove the address')
  } finally {
    deleting.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Receiving addresses"
      description="Your own addresses, one per network. An invoice takes the first free address that accepts its currency (lowest priority number first, then the one used longest ago) and keeps it until the invoice ends."
    >
      <template #actions>
        <button
          type="button"
          class="btn-secondary"
          :disabled="refreshing"
          aria-label="Refresh"
          @click="load(true)"
        >
          <RefreshCw :size="15" :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
        </button>
        <button
          v-if="auth.isAdmin"
          type="button"
          class="btn-primary"
          @click="openCreate"
        >
          <Plus :size="15" aria-hidden="true" />
          Add address
        </button>
      </template>
    </PageHeader>

    <div class="flex flex-wrap items-center gap-2">
      <SelectFilter
        v-model="networkFilter"
        label="Network"
        placeholder="All networks"
        :options="NETWORK_OPTIONS"
      />
    </div>

    <section class="card overflow-hidden">
      <DataTable :columns="columns" :rows="filteredRows" :loading="loading" caption="Receiving addresses">
        <template #cell-address="{ row }">
          <div class="min-w-0">
            <AddressDisplay :value="row.address" :href="row.explorer_url" :head="10" :tail="8" />
            <p v-if="row.label" class="mt-0.5 truncate text-xs text-muted">{{ row.label }}</p>
          </div>
        </template>
        <template #cell-network="{ row }">
          <NetworkBadge :network="row.network" :name="row.network_name" size="sm" />
        </template>
        <template #cell-currencies="{ row }">
          <div v-if="row.currencies.length" class="flex flex-wrap items-center gap-1.5">
            <span
              v-for="symbol in row.currencies"
              :key="symbol"
              class="inline-flex items-center gap-1 rounded-md border border-border bg-surface-2 px-1.5 py-0.5 text-xs font-medium"
            >
              <CoinLogo :currency="symbol" :size="14" />
              {{ symbol }}
            </span>
          </div>
          <span v-else class="text-xs text-muted">Any on {{ row.standard ?? row.network_name }}</span>
        </template>
        <template #cell-status="{ row }">
          <div class="flex flex-col gap-0.5">
            <StatusBadge :status="row.status" size="sm" context="Address status" />
            <RouterLink
              v-if="row.status === 'busy' && row.lease?.invoice"
              :to="`/admin/invoices/${row.lease.invoice.id}`"
              class="link mono text-[11px]"
            >
              {{ truncateMiddle(row.lease.invoice.id, 6, 4) }}
              <span class="text-muted">· until {{ formatRelative(row.lease.leased_until) }}</span>
            </RouterLink>
            <span v-else-if="row.invoices_count > 0" class="text-[11px] text-muted">
              {{ row.invoices_count }} invoice(s)
            </span>
          </div>
        </template>
        <template #cell-received="{ row }">
          <div v-if="nonZeroReceived(row.received).length" class="flex flex-col items-end gap-0.5">
            <AmountDisplay
              v-for="[currency, amount] in nonZeroReceived(row.received)"
              :key="currency"
              :value="amount"
              :currency="currency"
              size="sm"
            />
          </div>
          <span v-else class="text-xs text-muted">—</span>
        </template>
        <template #cell-priority="{ row }">
          <span class="mono text-xs">{{ row.priority }}</span>
        </template>
        <template #cell-actions="{ row }">
          <div v-if="auth.isAdmin" class="flex items-center justify-end gap-1">
            <button
              type="button"
              class="btn-ghost btn-sm"
              :aria-label="`${row.is_enabled ? 'Disable' : 'Enable'} ${row.address}`"
              @click="toggleEnabled(row)"
            >
              {{ row.is_enabled ? 'Disable' : 'Enable' }}
            </button>
            <button
              type="button"
              class="btn-ghost btn-sm"
              :aria-label="`Edit ${row.address}`"
              @click="openEdit(row)"
            >
              <Pencil :size="13" aria-hidden="true" />
            </button>
            <button
              type="button"
              class="btn-ghost btn-sm hover:text-danger"
              :disabled="row.status === 'busy'"
              :title="row.status === 'busy' ? 'Leased to an open invoice — disable it instead' : undefined"
              :aria-label="`Remove ${row.address}`"
              @click="deleteTarget = row"
            >
              <Trash2 :size="13" aria-hidden="true" />
            </button>
          </div>
        </template>
        <template #empty>
          <EmptyState
            :icon="ListChecks"
            title="No receiving addresses"
            description="Add an address from your own wallet, pick its network and the coins it may accept. Until then invoices use the xpub from the Wallet page, if one is set."
          >
            <button v-if="auth.isAdmin" type="button" class="btn-primary" @click="openCreate">
              <Plus :size="15" aria-hidden="true" />
              Add address
            </button>
          </EmptyState>
        </template>
      </DataTable>
    </section>

    <Modal
      :open="modalOpen"
      :title="editing ? 'Edit address' : 'Add receiving address'"
      size="sm"
      @close="modalOpen = false"
    >
      <form id="address-form" class="space-y-4" novalidate @submit.prevent="submit">
        <div>
          <label for="a-network" class="label">Network</label>
          <select
            id="a-network"
            v-model="form.network"
            class="input"
            :disabled="editing !== null"
            @change="onNetworkChange"
          >
            <option v-for="opt in NETWORK_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
          <p v-if="editing" class="hint">The network and the address cannot change once added.</p>
        </div>
        <div>
          <label for="a-address" class="label">Address <span v-if="!editing" class="text-danger">*</span></label>
          <input
            id="a-address"
            v-model="form.address"
            type="text"
            required
            :data-autofocus="!editing || undefined"
            :disabled="editing !== null"
            autocomplete="off"
            spellcheck="false"
            class="input mono"
            :class="errors.address ? 'input-error' : ''"
            :placeholder="form.network === 'tron' ? 'T…' : '0x…'"
          />
          <p v-if="errors.address" class="error-text">{{ errors.address }}</p>
          <p v-else-if="!editing" class="hint">
            Paste an address you control. Everything sent to it stays in your wallet.
          </p>
        </div>
        <fieldset>
          <legend class="label">Accepts</legend>
          <div class="space-y-2">
            <label class="flex cursor-pointer items-center gap-2.5">
              <input v-model="acceptsAll" type="checkbox" class="checkbox" />
              <span class="text-sm">Every coin enabled on this network</span>
            </label>
            <label
              v-for="symbol in availableCurrencies"
              :key="symbol"
              class="flex cursor-pointer items-center gap-2.5 pl-6"
            >
              <input
                type="checkbox"
                class="checkbox"
                :checked="acceptsAll || form.currencies.includes(symbol)"
                :disabled="acceptsAll"
                @change="toggleCurrency(symbol, ($event.target as HTMLInputElement).checked)"
              />
              <CoinLogo :currency="symbol" :size="16" />
              <span class="text-sm">{{ symbol }}</span>
            </label>
          </div>
          <p v-if="errors.currencies || errors['currencies.0']" class="error-text">
            {{ errors.currencies || errors['currencies.0'] }}
          </p>
          <p v-else-if="!acceptsAll && form.currencies.length === 0" class="error-text">
            Pick at least one coin, or accept every coin.
          </p>
        </fieldset>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="a-label" class="label">Label</label>
            <input
              id="a-label"
              v-model="form.label"
              type="text"
              maxlength="100"
              class="input"
              :class="errors.label ? 'input-error' : ''"
              placeholder="Ledger #1"
            />
            <p v-if="errors.label" class="error-text">{{ errors.label }}</p>
          </div>
          <div>
            <label for="a-priority" class="label">Priority</label>
            <input
              id="a-priority"
              v-model.number="form.priority"
              type="number"
              min="0"
              max="1000"
              class="input"
              :class="errors.priority ? 'input-error' : ''"
            />
            <p v-if="errors.priority" class="error-text">{{ errors.priority }}</p>
            <p v-else class="hint">Lower is picked first.</p>
          </div>
        </div>
        <label class="flex cursor-pointer items-center gap-2.5">
          <input v-model="form.is_enabled" type="checkbox" class="checkbox" />
          <span class="text-sm">Enabled — may be given to new invoices</span>
        </label>
      </form>
      <template #footer>
        <button type="button" class="btn-secondary" @click="modalOpen = false">Cancel</button>
        <button
          type="submit"
          form="address-form"
          class="btn-primary"
          :disabled="saving || (!acceptsAll && form.currencies.length === 0)"
        >
          <Spinner v-if="saving" :size="14" />
          {{ editing ? 'Save changes' : 'Add address' }}
        </button>
      </template>
    </Modal>

    <ConfirmDialog
      :open="deleteTarget !== null"
      title="Remove this address?"
      :message="`${deleteTarget?.address ?? ''} will no longer be offered to new invoices. Payments already recorded on it stay in the history and the address keeps being watched.`"
      confirm-label="Remove address"
      tone="danger"
      :loading="deleting"
      @close="deleteTarget = null"
      @confirm="remove"
    />
  </div>
</template>
