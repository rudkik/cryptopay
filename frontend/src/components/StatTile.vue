<script setup lang="ts">
import type { Component } from 'vue'
import Skeleton from './Skeleton.vue'

withDefaults(
  defineProps<{
    label: string
    icon?: Component
    loading?: boolean
    hint?: string
    tone?: 'primary' | 'success' | 'warning' | 'danger'
  }>(),
  { loading: false, tone: 'primary' },
)

const TONES = {
  primary: 'text-primary-hover bg-primary/12 border-primary/25',
  success: 'text-success bg-success/12 border-success/25',
  warning: 'text-warning bg-warning/12 border-warning/25',
  danger: 'text-danger bg-danger/12 border-danger/25',
} as const
</script>

<template>
  <div class="card group relative overflow-hidden p-5 transition-colors hover:border-primary/35">
    <div
      class="pointer-events-none absolute -right-10 -top-16 h-32 w-32 rounded-full bg-primary/10 opacity-0 blur-2xl transition-opacity duration-300 group-hover:opacity-100"
      aria-hidden="true"
    />
    <div class="relative flex items-start justify-between gap-3">
      <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ label }}</p>
      <span
        v-if="icon"
        class="flex h-8 w-8 items-center justify-center rounded-xl border"
        :class="TONES[tone]"
      >
        <component :is="icon" :size="15" aria-hidden="true" />
      </span>
    </div>
    <div class="relative mt-3">
      <Skeleton v-if="loading" width="w-24" height="h-7" />
      <slot v-else />
    </div>
    <p v-if="hint && !loading" class="relative mt-2 text-xs text-muted">{{ hint }}</p>
  </div>
</template>
