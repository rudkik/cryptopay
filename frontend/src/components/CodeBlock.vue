<script setup lang="ts">
import CopyButton from './CopyButton.vue'

withDefaults(defineProps<{ code: string; language?: string; title?: string }>(), { language: 'bash' })
</script>

<template>
  <figure class="overflow-hidden rounded-xl border border-border bg-bg/70">
    <figcaption
      class="flex items-center justify-between gap-3 border-b border-border bg-surface-2/60 px-3 py-2"
    >
      <span class="truncate text-[11px] font-medium uppercase tracking-wide text-muted">
        {{ title ?? language }}
      </span>
      <CopyButton :value="code" label="Code" :size="13" />
    </figcaption>
    <pre
      class="scrollbar-none overflow-x-auto px-4 py-3.5 text-[12.5px] leading-relaxed text-text/90"
    ><code class="font-mono">{{ code }}</code></pre>
  </figure>
</template>
