<script setup lang="ts">
import { computed } from 'vue'
import NetworkIcon from './NetworkIcon.vue'
import type { NetworkCode } from '@/api/types'

const props = withDefaults(
  defineProps<{
    network: NetworkCode | string
    /** Overrides the built-in display name (public checkout sends `network_name`). */
    name?: string | null
    size?: 'sm' | 'md'
    /** Render only the icon + short code. */
    compact?: boolean
  }>(),
  { size: 'md', compact: false },
)

const NAMES: Record<string, string> = {
  ethereum: 'Ethereum',
  bsc: 'BNB Smart Chain',
  tron: 'Tron',
}

const SHORT: Record<string, string> = { ethereum: 'ERC-20', bsc: 'BEP-20', tron: 'TRC-20' }

const label = computed(() => props.name || NAMES[props.network] || props.network)
const standard = computed(() => SHORT[props.network] ?? '')
</script>

<template>
  <!--
    The chip itself stays neutral: the chain logo already carries the colour, and
    a tinted pill behind it would fight the mark and cost text contrast.
  -->
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-border bg-surface-2 font-medium text-text"
    :class="size === 'sm' ? 'py-0.5 pl-1 pr-2.5 text-[11px]' : 'py-1 pl-1.5 pr-3 text-xs'"
  >
    <NetworkIcon :network="network" :size="size === 'sm' ? 18 : 22" />
    {{ compact ? standard || label : label }}
  </span>
</template>
