<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
      <div v-if="$slots.breadcrumb" class="mb-1.5">
        <slot name="breadcrumb" />
      </div>
      <h1 class="truncate text-xl font-semibold tracking-tight text-text sm:text-2xl">{{ title }}</h1>
      <p v-if="description" class="mt-1 text-sm text-muted">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>
