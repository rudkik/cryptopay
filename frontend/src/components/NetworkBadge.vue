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

const TINTS: Record<string, string> = {
  ethereum: 'border-[#627eea]/35 bg-[#627eea]/12 text-[#8fa2f0]',
  bsc: 'border-[#f0b90b]/35 bg-[#f0b90b]/12 text-[#f0c445]',
  tron: 'border-[#eb2f3a]/35 bg-[#eb2f3a]/12 text-[#f4737b]',
}

const label = computed(() => props.name || NAMES[props.network] || props.network)
const standard = computed(() => SHORT[props.network] ?? '')
const tint = computed(() => TINTS[props.network] ?? 'border-border bg-surface-2 text-muted')
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium"
    :class="[tint, size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs']"
  >
    <NetworkIcon :network="network" :size="size === 'sm' ? 11 : 13" />
    {{ compact ? standard || label : label }}
  </span>
</template>
