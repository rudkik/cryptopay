<script setup lang="ts" generic="T">
import Skeleton from './Skeleton.vue'
import type { Column } from './table'

const props = withDefaults(
  defineProps<{
    columns: Column[]
    rows: T[]
    loading?: boolean
    skeletonRows?: number
    /** Stable key accessor. */
    rowKey?: (row: T, index: number) => string | number
    /** Makes rows keyboard-activatable; emits `rowClick`. */
    clickable?: boolean
    caption?: string
  }>(),
  { loading: false, skeletonRows: 6, clickable: false },
)

const emit = defineEmits<{ rowClick: [row: T] }>()

const HIDE = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell' }

function keyFor(row: T, index: number): string | number {
  if (props.rowKey) return props.rowKey(row, index)
  const id = (row as { id?: unknown }).id
  return typeof id === 'string' || typeof id === 'number' ? id : index
}

function activate(row: T): void {
  if (props.clickable) emit('rowClick', row)
}

/** Fallback renderer used when a view does not provide a `cell-<key>` slot. */
function cellValue(row: T, key: string): string {
  const value = (row as Record<string, unknown>)[key]
  if (value === null || value === undefined || value === '') return '—'
  return String(value)
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-sm">
      <caption v-if="caption" class="sr-only">{{ caption }}</caption>
      <thead>
        <tr class="border-b border-border bg-surface-2">
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted"
            :class="[column.class, column.headerClass, column.hideBelow ? HIDE[column.hideBelow] : '']"
          >
            {{ column.label }}
          </th>
        </tr>
      </thead>

      <tbody v-if="loading">
        <tr v-for="i in skeletonRows" :key="`sk-${i}`" class="border-b border-border">
          <td
            v-for="column in columns"
            :key="column.key"
            class="px-4 py-3.5"
            :class="[column.class, column.hideBelow ? HIDE[column.hideBelow] : '']"
          >
            <Skeleton height="h-3.5" :width="i % 3 === 0 ? 'w-2/3' : 'w-4/5'" />
          </td>
        </tr>
      </tbody>

      <tbody v-else-if="rows.length">
        <tr
          v-for="(row, index) in rows"
          :key="keyFor(row, index)"
          class="table-row-hover border-b border-border last:border-0"
          :class="clickable ? 'cursor-pointer' : ''"
          :tabindex="clickable ? 0 : undefined"
          :role="clickable ? 'link' : undefined"
          @click="activate(row)"
          @keydown.enter.prevent="activate(row)"
          @keydown.space.prevent="activate(row)"
        >
          <td
            v-for="column in columns"
            :key="column.key"
            class="px-4 py-3.5 align-middle"
            :class="[column.class, column.hideBelow ? HIDE[column.hideBelow] : '']"
          >
            <slot :name="`cell-${column.key}`" :row="row" :index="index">
              {{ cellValue(row, column.key) }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="!loading && rows.length === 0">
      <slot name="empty" />
    </div>
  </div>
</template>
