<script setup lang="ts">
import CryptoLogo from './icons/CryptoLogo.vue'
import type { NetworkCode } from '@/api/types'

/**
 * Thin wrapper kept for call-site compatibility: every network mark is now the
 * real chain logo (see `icons/CryptoLogo.vue`) instead of a hand-drawn glyph.
 */
withDefaults(defineProps<{ network: NetworkCode | string; size?: number; title?: string }>(), {
  size: 16,
})
</script>

<template>
  <CryptoLogo :kind="network" :size="size" :title="title" />
</template>
