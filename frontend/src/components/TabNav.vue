<script setup lang="ts">
import { ref } from 'vue'
import type { TabItem } from './tabs'

defineProps<{ tabs: TabItem[] }>()
const active = defineModel<string>({ required: true })

const listRef = ref<HTMLElement | null>(null)

/** Roving arrow-key navigation between tabs (WAI-ARIA tabs pattern). */
function onKeydown(event: KeyboardEvent, tabs: TabItem[], index: number): void {
  const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End']
  if (!keys.includes(event.key)) return
  event.preventDefault()
  let next = index
  if (event.key === 'ArrowRight') next = (index + 1) % tabs.length
  if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length
  if (event.key === 'Home') next = 0
  if (event.key === 'End') next = tabs.length - 1
  active.value = tabs[next]!.key
  listRef.value?.querySelectorAll<HTMLElement>('[role="tab"]')[next]?.focus()
}
</script>

<template>
  <div
    ref="listRef"
    class="scrollbar-none flex gap-1 overflow-x-auto border-b border-border"
    role="tablist"
  >
    <button
      v-for="(tab, index) in tabs"
      :key="tab.key"
      type="button"
      role="tab"
      :id="`tab-${tab.key}`"
      :aria-selected="active === tab.key"
      :aria-controls="`panel-${tab.key}`"
      :tabindex="active === tab.key ? 0 : -1"
      class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors"
      :class="
        active === tab.key
          ? 'border-primary text-text'
          : 'border-transparent text-muted hover:border-border hover:text-text'
      "
      @click="active = tab.key"
      @keydown="onKeydown($event, tabs, index)"
    >
      {{ tab.label }}
      <span
        v-if="tab.count !== undefined && tab.count !== null"
        class="rounded-full px-1.5 py-0.5 text-[10px] tabular-nums"
        :class="active === tab.key ? 'bg-primary/20 text-primary-hover' : 'bg-surface-2 text-muted'"
      >
        {{ tab.count }}
      </span>
    </button>
  </div>
</template>
