<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Pencil, Plus, Trash2, Users } from 'lucide-vue-next'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import Modal from '@/components/Modal.vue'
import PageHeader from '@/components/PageHeader.vue'
import Pagination from '@/components/Pagination.vue'
import Spinner from '@/components/Spinner.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Column } from '@/components/table'
import { usersApi, type UserPayload } from '@/api/users'
import type { AdminUser } from '@/api/types'
import { usePaginatedList } from '@/composables/usePaginatedList'
import { fieldErrors, reportError } from '@/composables/useErrorHandler'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/format'
import { toast } from '@/utils/toast'

const auth = useAuthStore()

const { items, meta, loading, load, setPage } = usePaginatedList<AdminUser, Record<'q', string>>({
  defaultFilters: { q: '' },
  fetcher: (params) => usersApi.list(params),
  perPage: 25,
  syncQuery: false,
})

const columns: Column[] = [
  { key: 'name', label: 'User' },
  { key: 'role', label: 'Role' },
  { key: 'is_active', label: 'Status' },
  { key: 'created_at', label: 'Created', class: 'text-right', hideBelow: 'sm' },
  { key: 'actions', label: '', class: 'text-right w-px' },
]

const modalOpen = ref(false)
const editing = ref<AdminUser | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const form = reactive<UserPayload>({ name: '', email: '', password: '', role: 'viewer', is_active: true })

const deleteTarget = ref<AdminUser | null>(null)
const deleting = ref(false)

function openCreate(): void {
  editing.value = null
  Object.assign(form, { name: '', email: '', password: '', role: 'viewer', is_active: true })
  errors.value = {}
  modalOpen.value = true
}

function openEdit(user: AdminUser): void {
  editing.value = user
  Object.assign(form, {
    name: user.name,
    email: user.email,
    password: '',
    role: user.role,
    is_active: user.is_active,
  })
  errors.value = {}
  modalOpen.value = true
}

async function submit(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const payload: Partial<UserPayload> = {
      name: form.name.trim(),
      email: form.email.trim(),
      role: form.role,
      is_active: form.is_active,
    }
    // An empty password on edit means "keep the current one".
    if (form.password) payload.password = form.password

    if (editing.value) {
      await usersApi.update(editing.value.id, payload)
      toast.success('User updated')
    } else {
      await usersApi.create(payload as UserPayload)
      toast.success('User created')
    }
    modalOpen.value = false
    await load()
  } catch (error) {
    errors.value = fieldErrors(error)
    reportError(error, 'Could not save the user')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await usersApi.remove(deleteTarget.value.id)
    toast.success('User removed')
    deleteTarget.value = null
    await load()
  } catch (error) {
    reportError(error, 'Could not remove the user')
  } finally {
    deleting.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Admin users" description="Console accounts with admin or read-only access.">
      <template #actions>
        <button type="button" class="btn-primary" @click="openCreate">
          <Plus :size="15" aria-hidden="true" />
          New user
        </button>
      </template>
    </PageHeader>

    <section class="card overflow-hidden">
      <DataTable :columns="columns" :rows="items" :loading="loading" caption="Admin users">
        <template #cell-name="{ row }">
          <div class="flex min-w-0 items-center gap-3">
            <span
              class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-primary-hover text-[11px] font-semibold text-primary-on"
              aria-hidden="true"
            >
              {{ row.name.slice(0, 2).toUpperCase() }}
            </span>
            <div class="min-w-0">
              <p class="truncate font-medium">
                {{ row.name }}
                <span v-if="row.id === auth.user?.id" class="ml-1 text-xs text-muted">(you)</span>
              </p>
              <p class="truncate text-xs text-muted">{{ row.email }}</p>
            </div>
          </div>
        </template>
        <template #cell-role="{ row }">
          <StatusBadge :status="row.role" size="sm" context="Role" />
        </template>
        <template #cell-is_active="{ row }">
          <StatusBadge :status="row.is_active ? 'active' : 'inactive'" size="sm" />
        </template>
        <template #cell-created_at="{ row }">
          <span class="whitespace-nowrap text-xs text-muted">{{ formatDate(row.created_at) }}</span>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-1">
            <button
              type="button"
              class="btn-ghost btn-sm"
              :aria-label="`Edit ${row.name}`"
              @click="openEdit(row)"
            >
              <Pencil :size="13" aria-hidden="true" />
              Edit
            </button>
            <button
              v-if="row.id !== auth.user?.id"
              type="button"
              class="btn-ghost btn-sm hover:text-danger"
              :aria-label="`Remove ${row.name}`"
              @click="deleteTarget = row"
            >
              <Trash2 :size="13" aria-hidden="true" />
            </button>
          </div>
        </template>
        <template #empty>
          <EmptyState :icon="Users" title="No admin users" description="Create the first console account.">
            <button type="button" class="btn-primary" @click="openCreate">
              <Plus :size="15" aria-hidden="true" />
              New user
            </button>
          </EmptyState>
        </template>
      </DataTable>

      <Pagination :meta="meta" :disabled="loading" @change="setPage" />
    </section>

    <Modal
      :open="modalOpen"
      :title="editing ? 'Edit user' : 'New user'"
      size="sm"
      @close="modalOpen = false"
    >
      <form id="user-form" class="space-y-4" novalidate @submit.prevent="submit">
        <div>
          <label for="u-name" class="label">Name <span class="text-danger">*</span></label>
          <input
            id="u-name"
            v-model="form.name"
            type="text"
            required
            data-autofocus
            class="input"
            :class="errors.name ? 'input-error' : ''"
          />
          <p v-if="errors.name" class="error-text">{{ errors.name }}</p>
        </div>
        <div>
          <label for="u-email" class="label">Email <span class="text-danger">*</span></label>
          <input
            id="u-email"
            v-model="form.email"
            type="email"
            required
            autocomplete="off"
            class="input"
            :class="errors.email ? 'input-error' : ''"
          />
          <p v-if="errors.email" class="error-text">{{ errors.email }}</p>
        </div>
        <div>
          <label for="u-password" class="label">
            Password <span v-if="!editing" class="text-danger">*</span>
          </label>
          <input
            id="u-password"
            v-model="form.password"
            type="password"
            :required="!editing"
            autocomplete="new-password"
            class="input"
            :class="errors.password ? 'input-error' : ''"
          />
          <p v-if="errors.password" class="error-text">{{ errors.password }}</p>
          <p v-else-if="editing" class="hint">Leave empty to keep the current password.</p>
        </div>
        <div>
          <label for="u-role" class="label">Role</label>
          <select id="u-role" v-model="form.role" class="input">
            <option value="admin">Admin — full access</option>
            <option value="viewer">Viewer — read only</option>
          </select>
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
        <button type="button" class="btn-secondary" @click="modalOpen = false">Cancel</button>
        <button type="submit" form="user-form" class="btn-primary" :disabled="saving">
          <Spinner v-if="saving" :size="14" />
          {{ editing ? 'Save changes' : 'Create user' }}
        </button>
      </template>
    </Modal>

    <ConfirmDialog
      :open="deleteTarget !== null"
      title="Remove this user?"
      :message="`${deleteTarget?.name ?? ''} will lose access to the console immediately.`"
      confirm-label="Remove user"
      tone="danger"
      :loading="deleting"
      @close="deleteTarget = null"
      @confirm="remove"
    />
  </div>
</template>
