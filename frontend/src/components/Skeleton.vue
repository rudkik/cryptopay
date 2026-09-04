<script setup lang="ts">
withDefaults(
  defineProps<{
    /** Tailwind width class, e.g. "w-32" or "w-full". */
    width?: string
    height?: string
    rounded?: string
    /** Render N stacked bars. */
    lines?: number
  }>(),
  { width: 'w-full', height: 'h-4', rounded: 'rounded-lg', lines: 1 },
)
</script>

<template>
  <div class="space-y-2" aria-hidden="true">
    <div
      v-for="i in lines"
      :key="i"
      class="relative overflow-hidden bg-[#ecebe5]"
      :class="[width, height, rounded, lines > 1 && i === lines ? 'w-2/3' : '']"
    >
      <div
        class="absolute inset-0 -translate-x-full animate-shimmer bg-gradient-to-r from-transparent via-white/70 to-transparent"
      />
    </div>
  </div>
</template>
