<script setup lang="ts">
import { Search, X } from 'lucide-vue-next'

defineProps<{ hasFilters?: boolean; searchLabel?: string }>()
const emit = defineEmits<{ reset: [] }>()
const search = defineModel<string>('search')
</script>

<template>
  <div class="flex flex-col gap-3 border-b border-border px-4 py-3.5 lg:flex-row lg:items-center">
    <div v-if="search !== undefined" class="relative min-w-0 flex-1 lg:max-w-xs">
      <Search
        :size="15"
        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
        aria-hidden="true"
      />
      <input
        v-model="search"
        type="search"
        class="input pl-9"
        :placeholder="searchLabel ?? 'Search…'"
        :aria-label="searchLabel ?? 'Search'"
      />
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <slot />
      <button
        v-if="hasFilters"
        type="button"
        class="btn-ghost btn-sm"
        @click="emit('reset')"
      >
        <X :size="13" aria-hidden="true" />
        Clear
      </button>
    </div>

    <div v-if="$slots.actions" class="flex items-center gap-2 lg:ml-auto">
      <slot name="actions" />
    </div>
  </div>
</template>
