<script setup lang="ts">
import CryptoLogo from './icons/CryptoLogo.vue'

/** Token mark for a currency code (`USDT` / `USDC`). */
withDefaults(defineProps<{ currency: string | null | undefined; size?: number; title?: string }>(), {
  size: 20,
})
</script>

<template>
  <CryptoLogo v-if="currency" :kind="currency" :size="size" :title="title" />
</template>
