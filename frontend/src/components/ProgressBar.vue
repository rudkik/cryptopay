<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    /** 0–100. */
    value: number
    tone?: 'primary' | 'success' | 'warning' | 'danger'
    height?: 'sm' | 'md'
    label?: string
    animated?: boolean
  }>(),
  { tone: 'primary', height: 'md', animated: false },
)

const TONES = {
  primary: 'from-primary to-accent',
  success: 'from-success to-[#2f9e5c]',
  warning: 'from-warning to-[#d97a12]',
  danger: 'from-danger to-[#dc4040]',
} as const

const width = computed(() => `${Math.max(0, Math.min(100, props.value))}%`)
</script>

<template>
  <div
    class="w-full overflow-hidden rounded-full bg-border"
    :class="height === 'sm' ? 'h-1.5' : 'h-2.5'"
    role="progressbar"
    :aria-valuenow="Math.round(value)"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-label="label"
  >
    <div
      class="h-full rounded-full bg-gradient-to-r transition-[width] duration-700 ease-out"
      :class="[TONES[tone], animated ? 'shadow-glow-sm' : '']"
      :style="{ width }"
    />
  </div>
</template>
