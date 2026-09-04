<script setup lang="ts">
import { computed } from 'vue'
import CoinLogo from './CoinLogo.vue'
import { splitForDisplay } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    /** Decimal string from the API — never parsed into a float. */
    value: string | number | null | undefined
    currency?: string | null
    size?: 'sm' | 'md' | 'lg' | 'xl'
    maxDecimals?: number
    /** Dim the fractional tail so the integer part reads first. */
    dimFraction?: boolean
    muted?: boolean
    /** Prefix the currency with its token mark — for prominent amounts. */
    logo?: boolean
  }>(),
  { size: 'md', maxDecimals: 8, dimFraction: true, muted: false, logo: false },
)

const parts = computed(() => splitForDisplay(props.value, props.maxDecimals))

const SIZES: Record<NonNullable<typeof props.size>, string> = {
  sm: 'text-[13px]',
  md: 'text-sm',
  lg: 'text-xl',
  xl: 'text-4xl sm:text-5xl',
}

const LOGO_SIZES: Record<NonNullable<typeof props.size>, number> = {
  sm: 18,
  md: 20,
  lg: 24,
  xl: 36,
}
</script>

<template>
  <span
    class="inline-flex items-baseline font-mono tabular-nums tracking-tight"
    :class="[SIZES[size], muted ? 'text-muted' : 'text-text']"
  >
    <span class="font-medium">{{ parts.head }}</span>
    <span v-if="parts.tail" :class="dimFraction ? 'opacity-60' : ''">{{ parts.tail }}</span>
    <span
      v-if="currency"
      class="inline-flex items-center gap-1 font-sans font-medium text-muted"
      :class="size === 'xl' ? 'ml-2 text-lg' : size === 'lg' ? 'ml-1.5 text-xs' : 'ml-1 text-[11px]'"
    >
      <CoinLogo v-if="logo" :currency="currency" :size="LOGO_SIZES[size]" class="self-center" />
      {{ currency }}
    </span>
  </span>
</template>
