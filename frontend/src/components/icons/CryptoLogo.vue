<script setup lang="ts">
import { computed, type Component } from 'vue'
import BnbLogo from './BnbLogo.vue'
import EthereumLogo from './EthereumLogo.vue'
import TronLogo from './TronLogo.vue'
import UsdcLogo from './UsdcLogo.vue'
import UsdtLogo from './UsdtLogo.vue'

/**
 * Single entry point for the real network / token marks. Every logo is a Vue
 * SFC that inlines the original CC0 artwork, so nothing goes through `v-html`
 * and no `<img>` points at an external host (the app runs under a strict
 * `img-src` CSP).
 */
type CryptoKind = 'ethereum' | 'bsc' | 'tron' | 'USDT' | 'USDC'

const props = withDefaults(
  defineProps<{
    /** Network code (`ethereum` | `bsc` | `tron`) or currency (`USDT` | `USDC`). */
    kind: CryptoKind | string
    size?: number
    /** Supplying a title turns the mark into an announced image. */
    title?: string
  }>(),
  { size: 16 },
)

const LOGOS: Record<string, Component> = {
  ethereum: EthereumLogo,
  bsc: BnbLogo,
  bnb: BnbLogo,
  tron: TronLogo,
  USDT: UsdtLogo,
  USDC: UsdcLogo,
}

const logo = computed<Component | null>(() => LOGOS[props.kind] ?? LOGOS[props.kind.toUpperCase()] ?? null)
</script>

<template>
  <component :is="logo" v-if="logo" :size="size" :title="title" />
  <!-- Unknown asset: a neutral ring so layouts keep their rhythm. -->
  <svg
    v-else
    :width="size"
    :height="size"
    viewBox="0 0 32 32"
    fill="none"
    class="shrink-0 text-muted"
    :role="title ? 'img' : undefined"
    :aria-hidden="title ? undefined : true"
  >
    <title v-if="title">{{ title }}</title>
    <circle cx="16" cy="16" r="14" fill="currentColor" fill-opacity=".12" />
    <circle cx="16" cy="16" r="14" stroke="currentColor" stroke-opacity=".45" stroke-width="2" />
  </svg>
</template>
