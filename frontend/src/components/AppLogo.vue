<script setup lang="ts">
withDefaults(defineProps<{ size?: number }>(), { size: 28 })
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 32 32" fill="none" aria-hidden="true" class="shrink-0">
    <defs>
      <linearGradient :id="`logo-${size}`" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#a78bfa" />
        <stop offset="0.55" stop-color="#8b5cf6" />
        <stop offset="1" stop-color="#d946ef" />
      </linearGradient>
    </defs>
    <rect width="32" height="32" rx="8" fill="#150d27" />
    <path d="M16 4.5 26 12l-10 15.5L6 12 16 4.5Z" :fill="`url(#logo-${size})`" />
    <path d="M16 4.5 26 12H6l10-7.5Z" fill="#ece8f6" fill-opacity=".22" />
    <path d="M11.2 12 16 27.5 20.8 12" fill="none" stroke="#0a0613" stroke-opacity=".3" stroke-width="1.2" />
  </svg>
</template>
