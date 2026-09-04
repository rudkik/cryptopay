<script setup lang="ts">
withDefaults(defineProps<{ size?: number; tone?: 'success' | 'warning' }>(), {
  size: 72,
  tone: 'success',
})
</script>

<template>
  <div class="relative inline-flex items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }">
    <span
      class="absolute inset-0 rounded-full"
      :class="tone === 'success' ? 'bg-success/20' : 'bg-warning/20'"
      style="animation: pulse-ring 2s cubic-bezier(0.24, 0, 0.38, 1) infinite"
      aria-hidden="true"
    />
    <svg
      class="relative"
      :width="size"
      :height="size"
      viewBox="0 0 52 52"
      fill="none"
      role="img"
      aria-label="Payment confirmed"
    >
      <circle
        cx="26"
        cy="26"
        r="24"
        :stroke="tone === 'success' ? '#136c33' : '#a24a08'"
        stroke-width="2"
        stroke-opacity=".35"
      />
      <circle
        cx="26"
        cy="26"
        r="24"
        :fill="tone === 'success' ? '#e6f6ec' : '#fdf3e1'"
      />
      <path
        d="M15 27.5 22.5 35 37.5 19"
        :stroke="tone === 'success' ? '#136c33' : '#a24a08'"
        stroke-width="3.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-dasharray="40"
        stroke-dashoffset="40"
        class="animate-draw-check"
      />
    </svg>
  </div>
</template>
