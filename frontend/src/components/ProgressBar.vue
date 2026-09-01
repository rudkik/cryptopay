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
  success: 'from-success to-[#5eead4]',
  warning: 'from-warning to-[#fcd34d]',
  danger: 'from-danger to-[#fca5a5]',
} as const

const width = computed(() => `${Math.max(0, Math.min(100, props.value))}%`)
</script>

<template>
  <div
    class="w-full overflow-hidden rounded-full bg-surface-2"
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
