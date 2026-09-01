<script setup lang="ts">
const props = defineProps<{
  label: string
  options: { value: string; label: string }[]
  /** Placeholder shown for the empty value. */
  placeholder?: string
}>()

const model = defineModel<string>({ required: true })
</script>

<template>
  <label class="relative inline-flex">
    <span class="sr-only">{{ label }}</span>
    <select
      v-model="model"
      class="input min-w-[130px] appearance-none py-1.5 pr-8 text-xs"
      :class="model ? 'border-primary/50 text-text' : 'text-muted'"
      :aria-label="label"
    >
      <option value="">{{ props.placeholder ?? label }}</option>
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>
    <svg
      class="pointer-events-none absolute right-2.5 top-1/2 h-3 w-3 -translate-y-1/2 text-muted"
      viewBox="0 0 12 12"
      fill="none"
      aria-hidden="true"
    >
      <path d="m3 4.5 3 3 3-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
    </svg>
  </label>
</template>
