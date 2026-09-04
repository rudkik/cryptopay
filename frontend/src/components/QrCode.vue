<script setup lang="ts">
import { ref, watch } from 'vue'
import QRCode from 'qrcode'

const props = withDefaults(
  defineProps<{
    value: string
    size?: number
    /** Accessible description of what the code encodes. */
    label?: string
  }>(),
  { size: 208, label: 'Payment QR code' },
)

const svg = ref<string>('')
const failed = ref(false)

/**
 * Defence in depth for the `v-html` below. `qrcode` renders the payload into a
 * module matrix and emits only `<svg>` + `<path>` elements — the value is never
 * interpolated as markup — but because this component is mounted on the public
 * checkout we refuse to inject anything that does not match that exact shape.
 */
const SVG_SHAPE = /^<svg[^>]*>(?:<path[^>]*\/>)+<\/svg>$/

function isPlainQrSvg(markup: string): boolean {
  return SVG_SHAPE.test(markup.trim()) && !/[<\s]on[a-z]+\s*=|<script|xlink:href|href\s*=/i.test(markup)
}

async function render(): Promise<void> {
  failed.value = false
  if (!props.value) {
    svg.value = ''
    return
  }
  try {
    const markup = await QRCode.toString(props.value, {
      type: 'svg',
      errorCorrectionLevel: 'M',
      margin: 1,
      width: props.size,
      color: { dark: '#1c1b1f', light: '#ffffff' },
    })
    if (!isPlainQrSvg(markup)) throw new Error('unexpected QR markup')
    svg.value = markup
  } catch {
    failed.value = true
    svg.value = ''
  }
}

watch(() => [props.value, props.size], render, { immediate: true })
</script>

<template>
  <div
    class="relative inline-flex items-center justify-center rounded-2xl border border-border bg-white p-3 shadow-card"
    :style="{ width: `${size + 24}px`, height: `${size + 24}px` }"
  >
    <!--
      qrcode's SVG output is generated locally from `value`; no external HTML is
      injected, and `isPlainQrSvg()` re-checks the shape before it is bound.
    -->
    <div
      v-if="svg"
      class="h-full w-full [&>svg]:h-full [&>svg]:w-full"
      role="img"
      :aria-label="label"
      v-html="svg"
    />
    <p v-else-if="failed" class="px-4 text-center text-xs text-muted">QR code unavailable</p>
    <div v-else class="h-full w-full animate-pulse rounded-lg bg-surface-2" aria-hidden="true" />
  </div>
</template>
