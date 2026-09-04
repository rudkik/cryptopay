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
  primary: 'text-primary-hover bg-primary-soft border-primary/20',
  success: 'text-success bg-success-soft border-success/20',
  warning: 'text-warning bg-warning-soft border-warning/20',
  danger: 'text-danger bg-danger-soft border-danger/20',
} as const
</script>

<template>
  <div class="card group relative overflow-hidden p-5 transition-colors hover:border-primary/40">
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
