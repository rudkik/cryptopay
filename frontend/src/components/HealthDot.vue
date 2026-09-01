<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ healthy: boolean; enabled?: boolean }>()

const state = computed(() => {
  if (props.enabled === false) return { cls: 'bg-muted', ping: false, text: 'disabled' }
  return props.healthy
    ? { cls: 'bg-success', ping: false, text: 'healthy' }
    : { cls: 'bg-danger', ping: true, text: 'unhealthy' }
})
</script>

<template>
  <span class="relative flex h-2 w-2 shrink-0" role="img" :aria-label="`Watcher ${state.text}`">
    <span
      v-if="state.ping"
      class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
      :class="state.cls"
    />
    <span class="relative inline-flex h-2 w-2 rounded-full" :class="state.cls" />
  </span>
</template>
