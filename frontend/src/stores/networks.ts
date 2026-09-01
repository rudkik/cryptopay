import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { networksApi } from '@/api/networks'
import type { Network } from '@/api/types'

function unwrap(payload: Network[] | { data: Network[] }): Network[] {
  return Array.isArray(payload) ? payload : (payload.data ?? [])
}

/** Shared network state — powers the topbar watcher health dots and filter selects. */
export const useNetworksStore = defineStore('networks', () => {
  const items = ref<Network[]>([])
  const loading = ref(false)
  const loadedAt = ref<number | null>(null)
  const error = ref<string | null>(null)

  const enabled = computed(() => items.value.filter((n) => n.is_enabled))
  const unhealthy = computed(() => enabled.value.filter((n) => !n.watcher_healthy))
  const allHealthy = computed(() => enabled.value.length > 0 && unhealthy.value.length === 0)

  /** In-flight request, so concurrent callers await the same fetch. */
  let inFlight: Promise<void> | null = null

  async function load(force = false): Promise<void> {
    if (inFlight) return inFlight
    if (!force && loadedAt.value && Date.now() - loadedAt.value < 30_000) return
    loading.value = true
    error.value = null
    inFlight = (async () => {
      try {
        items.value = unwrap(await networksApi.list())
        loadedAt.value = Date.now()
      } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load networks'
        throw e
      } finally {
        loading.value = false
        inFlight = null
      }
    })()
    return inFlight
  }

  function replace(network: Network): void {
    const index = items.value.findIndex((n) => n.code === network.code)
    if (index !== -1) items.value.splice(index, 1, network)
  }

  function reset(): void {
    items.value = []
    loadedAt.value = null
  }

  return { items, loading, error, enabled, unhealthy, allHealthy, load, replace, reset }
})
