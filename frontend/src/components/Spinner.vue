<script setup lang="ts">
withDefaults(defineProps<{ size?: number; label?: string }>(), { size: 16, label: 'Loading' })
</script>

<template>
  <svg
    class="animate-spin text-current"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    role="status"
    :aria-label="label"
  >
    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".2" stroke-width="3" />
    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
  </svg>
</template>
