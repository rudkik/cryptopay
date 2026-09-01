<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import type { PaginationMeta } from '@/api/types'

const props = defineProps<{ meta: PaginationMeta; disabled?: boolean }>()
const emit = defineEmits<{ change: [page: number] }>()

const from = computed(() => {
  if (props.meta.total === 0) return 0
  return props.meta.from ?? (props.meta.current_page - 1) * props.meta.per_page + 1
})
const to = computed(() => {
  if (props.meta.total === 0) return 0
  return props.meta.to ?? Math.min(props.meta.current_page * props.meta.per_page, props.meta.total)
})

/** Window of page numbers with ellipsis markers (0 = gap). */
const pages = computed<number[]>(() => {
  const last = props.meta.last_page
  const current = props.meta.current_page
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)

  const out = new Set<number>([1, last, current])
  for (let offset = 1; offset <= 1; offset += 1) {
    if (current - offset > 1) out.add(current - offset)
    if (current + offset < last) out.add(current + offset)
  }
  const sorted = [...out].sort((a, b) => a - b)
  const withGaps: number[] = []
  sorted.forEach((page, index) => {
    if (index > 0 && page - sorted[index - 1]! > 1) withGaps.push(0)
    withGaps.push(page)
  })
  return withGaps
})

function go(page: number): void {
  if (props.disabled || page < 1 || page > props.meta.last_page || page === props.meta.current_page) return
  emit('change', page)
}
</script>

<template>
  <nav
    v-if="meta.total > 0"
    class="flex flex-col items-center justify-between gap-3 border-t border-border px-4 py-3 sm:flex-row"
    aria-label="Pagination"
  >
    <p class="text-xs text-muted">
      Showing <span class="font-medium text-text">{{ from }}–{{ to }}</span> of
      <span class="font-medium text-text">{{ meta.total }}</span>
    </p>

    <div v-if="meta.last_page > 1" class="flex items-center gap-1">
      <button
        type="button"
        class="btn-ghost btn-sm"
        :disabled="disabled || meta.current_page <= 1"
        aria-label="Previous page"
        @click="go(meta.current_page - 1)"
      >
        <ChevronLeft :size="15" aria-hidden="true" />
        <span class="hidden sm:inline">Prev</span>
      </button>

      <template v-for="(page, index) in pages" :key="`${page}-${index}`">
        <span v-if="page === 0" class="px-1.5 text-xs text-muted" aria-hidden="true">…</span>
        <button
          v-else
          type="button"
          class="min-w-[32px] rounded-lg px-2 py-1.5 text-xs font-medium transition-colors"
          :class="
            page === meta.current_page
              ? 'bg-primary text-white'
              : 'text-muted hover:bg-surface-2 hover:text-text'
          "
          :aria-current="page === meta.current_page ? 'page' : undefined"
          :aria-label="`Page ${page}`"
          :disabled="disabled"
          @click="go(page)"
        >
          {{ page }}
        </button>
      </template>

      <button
        type="button"
        class="btn-ghost btn-sm"
        :disabled="disabled || meta.current_page >= meta.last_page"
        aria-label="Next page"
        @click="go(meta.current_page + 1)"
      >
        <span class="hidden sm:inline">Next</span>
        <ChevronRight :size="15" aria-hidden="true" />
      </button>
    </div>
  </nav>
</template>
