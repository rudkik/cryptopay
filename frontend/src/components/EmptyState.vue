<script setup lang="ts">
import type { Component } from 'vue'
import { Inbox } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    icon?: Component
    title: string
    description?: string
    compact?: boolean
  }>(),
  { compact: false },
)
</script>

<template>
  <div
    class="flex flex-col items-center justify-center gap-3 text-center"
    :class="compact ? 'px-4 py-10' : 'px-6 py-16'"
  >
    <div
      class="flex h-12 w-12 items-center justify-center rounded-2xl border border-border bg-surface-2 text-muted"
    >
      <component :is="icon ?? Inbox" :size="20" aria-hidden="true" />
    </div>
    <div class="space-y-1">
      <p class="text-sm font-semibold text-text">{{ title }}</p>
      <p v-if="description" class="mx-auto max-w-sm text-sm text-muted">{{ description }}</p>
    </div>
    <slot />
  </div>
</template>
