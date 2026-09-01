<script setup lang="ts">
import type { NetworkCode } from '@/api/types'

withDefaults(defineProps<{ network: NetworkCode | string; size?: number }>(), { size: 14 })
</script>

<template>
  <!-- Hand-drawn marks: no external icon dependency, crisp at 12–24px. -->
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
    class="shrink-0"
  >
    <template v-if="network === 'ethereum'">
      <path d="M12 2.5 5.5 12.3 12 16l6.5-3.7L12 2.5Z" fill="currentColor" fill-opacity=".55" />
      <path d="M12 2.5 5.5 12.3 12 9.4V2.5Z" fill="currentColor" />
      <path d="M12 17.4 5.5 13.6 12 21.5l6.5-7.9-6.5 3.8Z" fill="currentColor" fill-opacity=".8" />
    </template>
    <template v-else-if="network === 'bsc'">
      <path
        d="M12 2.6 15.2 5.8 12 9 8.8 5.8 12 2.6Zm5.9 5.9L21.1 11.7 17.9 14.9 14.7 11.7l3.2-3.2ZM6.1 8.5l3.2 3.2-3.2 3.2L2.9 11.7l3.2-3.2ZM12 14.4l3.2 3.2L12 20.8l-3.2-3.2 3.2-3.2Z"
        fill="currentColor"
      />
      <path d="M12 8.7l3 3-3 3-3-3 3-3Z" fill="currentColor" fill-opacity=".6" />
    </template>
    <template v-else-if="network === 'tron'">
      <path
        d="M3.4 4.2 20 7.4 12.6 21 3.4 4.2Zm2.9 2.1 5.1 9.4.9-6.6-6-2.8Zm7.9 3.3-.8 6.1 3.9-7.1-3.1 1ZM7.9 5.9l8.5 1.6-1.6.5-6.9-2.1Z"
        fill="currentColor"
      />
    </template>
    <template v-else>
      <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.8" />
    </template>
  </svg>
</template>
