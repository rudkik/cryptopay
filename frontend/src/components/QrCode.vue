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

async function render(): Promise<void> {
  failed.value = false
  if (!props.value) {
    svg.value = ''
    return
  }
  try {
    svg.value = await QRCode.toString(props.value, {
      type: 'svg',
      errorCorrectionLevel: 'M',
      margin: 1,
      width: props.size,
      color: { dark: '#0a0613', light: '#ffffff' },
    })
  } catch {
    failed.value = true
    svg.value = ''
  }
}

watch(() => [props.value, props.size], render, { immediate: true })
</script>

<template>
  <div
    class="relative inline-flex items-center justify-center rounded-2xl bg-white p-3 shadow-glow"
    :style="{ width: `${size + 24}px`, height: `${size + 24}px` }"
  >
    <!-- qrcode's SVG output is generated locally from `value`; no external HTML is injected. -->
    <div
      v-if="svg"
      class="h-full w-full [&>svg]:h-full [&>svg]:w-full"
      role="img"
      :aria-label="label"
      v-html="svg"
    />
    <p v-else-if="failed" class="px-4 text-center text-xs text-bg">QR code unavailable</p>
    <div v-else class="h-full w-full animate-pulse rounded-lg bg-neutral-200" aria-hidden="true" />
  </div>
</template>
