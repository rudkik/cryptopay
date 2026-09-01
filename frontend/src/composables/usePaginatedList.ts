import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { LocationQueryRaw } from 'vue-router'
import type { Paginated, PaginationMeta } from '@/api/types'
import { reportError } from './useErrorHandler'

export interface UsePaginatedListOptions<TFilters extends Record<string, string>> {
  /** Default (empty) filter values — also defines the filter keys read from the URL. */
  defaultFilters: TFilters
  fetcher: (params: Record<string, string | number>) => Promise<Paginated<unknown>>
  perPage?: number
  /** Sync filters + page into the query string so the view is shareable/back-navigable. */
  syncQuery?: boolean
}

const EMPTY_META: PaginationMeta = { current_page: 1, last_page: 1, per_page: 25, total: 0 }

/**
 * Shared list plumbing: filters <-> URL query, pagination, loading + error state.
 * `TItem` is inferred by the caller through a cast on `items`.
 */
export function usePaginatedList<TItem, TFilters extends Record<string, string>>(
  options: UsePaginatedListOptions<TFilters>,
) {
  const route = useRoute()
  const router = useRouter()
  const syncQuery = options.syncQuery !== false
  const perPage = options.perPage ?? 25

  const filterKeys = Object.keys(options.defaultFilters) as (keyof TFilters & string)[]

  function filtersFromQuery(): TFilters {
    const next = { ...options.defaultFilters }
    if (!syncQuery) return next
    for (const key of filterKeys) {
      const value = route.query[key]
      if (typeof value === 'string') next[key] = value as TFilters[typeof key]
    }
    return next
  }

  const filters = ref(filtersFromQuery()) as import('vue').Ref<TFilters>
  const page = ref(syncQuery ? Number(route.query.page ?? 1) || 1 : 1)
  const items = ref<TItem[]>([]) as import('vue').Ref<TItem[]>
  const meta = ref<PaginationMeta>({ ...EMPTY_META, per_page: perPage })
  const loading = ref(false)
  const failed = ref(false)

  const isEmpty = computed(() => !loading.value && items.value.length === 0)
  const hasFilters = computed(() =>
    filterKeys.some((key) => filters.value[key] !== options.defaultFilters[key]),
  )

  let requestId = 0

  async function load(): Promise<void> {
    const current = ++requestId
    loading.value = true
    failed.value = false
    try {
      const params: Record<string, string | number> = { page: page.value, per_page: perPage }
      for (const key of filterKeys) {
        const value = filters.value[key]
        if (value) params[key] = value
      }
      const response = await options.fetcher(params)
      if (current !== requestId) return // a newer request superseded this one
      items.value = (response.data ?? []) as TItem[]
      meta.value = response.meta ?? { ...EMPTY_META, per_page: perPage, total: items.value.length }
    } catch (error) {
      if (current !== requestId) return
      failed.value = true
      items.value = []
      reportError(error, 'Failed to load the list')
    } finally {
      if (current === requestId) loading.value = false
    }
  }

  function pushQuery(): void {
    if (!syncQuery) return
    const query: LocationQueryRaw = { ...route.query }
    for (const key of filterKeys) {
      const value = filters.value[key]
      if (value) query[key] = value
      else delete query[key]
    }
    if (page.value > 1) query.page = String(page.value)
    else delete query.page
    void router.replace({ query })
  }

  function setPage(next: number): void {
    if (next === page.value) return
    page.value = next
    pushQuery()
    void load()
  }

  function resetFilters(): void {
    filters.value = { ...options.defaultFilters }
  }

  let debounce: number | undefined
  watch(
    filters,
    () => {
      window.clearTimeout(debounce)
      debounce = window.setTimeout(() => {
        page.value = 1
        pushQuery()
        void load()
      }, 250)
    },
    { deep: true },
  )

  return { filters, page, items, meta, loading, failed, isEmpty, hasFilters, load, setPage, resetFilters }
}
