<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Activity } from 'lucide-vue-next'
import HealthDot from './HealthDot.vue'
import NetworkIcon from './NetworkIcon.vue'
import { useNetworksStore } from '@/stores/networks'
import { formatRelative } from '@/utils/format'

const networks = useNetworksStore()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

const summary = computed(() => {
  if (networks.loading && networks.items.length === 0) return 'Checking watchers…'
  if (networks.enabled.length === 0) return 'No networks enabled'
  return networks.unhealthy.length === 0
    ? 'All watchers healthy'
    : `${networks.unhealthy.length} watcher${networks.unhealthy.length > 1 ? 's' : ''} degraded`
})

function onDocumentClick(event: MouseEvent): void {
  if (open.value && root.value && !root.value.contains(event.target as Node)) open.value = false
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  void networks.load()
})
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="flex items-center gap-2 rounded-xl border border-border-strong bg-surface px-2.5 py-2 transition-colors hover:border-primary/50"
      :aria-expanded="open"
      aria-haspopup="true"
      :aria-label="`Watcher status — ${summary}`"
      @click="open = !open"
      @keydown.esc="open = false"
    >
      <Activity :size="14" class="text-muted" aria-hidden="true" />
      <span class="flex items-center gap-1.5">
        <HealthDot
          v-for="network in networks.items"
          :key="network.code"
          :healthy="network.watcher_healthy"
          :enabled="network.is_enabled"
        />
        <span v-if="networks.items.length === 0" class="h-2 w-2 rounded-full bg-border-strong" />
      </span>
    </button>

    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 -translate-y-1"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="card-glass absolute right-0 z-40 mt-2 w-72 p-3"
        role="dialog"
        aria-label="Watcher health"
      >
        <p class="px-1 pb-2 text-xs font-medium uppercase tracking-wide text-muted">{{ summary }}</p>
        <ul class="space-y-1">
          <li
            v-for="network in networks.items"
            :key="network.code"
            class="flex items-center justify-between gap-3 rounded-lg px-1.5 py-2 hover:bg-surface-2"
          >
            <span class="flex min-w-0 items-center gap-2">
              <NetworkIcon :network="network.code" :size="20" />
              <span class="truncate text-sm">{{ network.name }}</span>
            </span>
            <span class="flex shrink-0 items-center gap-2 text-right">
              <span class="mono text-[11px] text-muted">
                {{ network.last_scanned_block ?? '—' }}
              </span>
              <HealthDot :healthy="network.watcher_healthy" :enabled="network.is_enabled" />
            </span>
          </li>
          <li v-if="networks.items.length === 0" class="px-1.5 py-2 text-sm text-muted">
            No network data.
          </li>
        </ul>
        <p v-if="networks.items[0]?.watcher_seen_at" class="px-1.5 pt-2 text-[11px] text-muted">
          Last heartbeat {{ formatRelative(networks.items[0].watcher_seen_at) }}
        </p>
        <RouterLink
          to="/admin/networks"
          class="mt-2 block rounded-lg px-1.5 py-2 text-xs text-primary-hover hover:bg-surface-2"
          @click="open = false"
        >
          Manage networks →
        </RouterLink>
      </div>
    </Transition>
  </div>
</template>
