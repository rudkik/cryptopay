<script setup lang="ts">
import { ArrowLeft } from 'lucide-vue-next'
import AppLogo from '@/components/AppLogo.vue'
</script>

<template>
  <div class="glow-bg flex min-h-screen flex-col items-center justify-center gap-6 px-4 text-center">
    <AppLogo :size="40" />
    <div>
      <p class="text-5xl font-semibold tracking-tight text-primary-hover">404</p>
      <h1 class="mt-3 text-lg font-semibold">Page not found</h1>
      <p class="mt-1.5 text-sm text-muted">
        The page you are looking for does not exist or has been moved.
      </p>
    </div>
    <RouterLink to="/admin" class="btn-secondary">
      <ArrowLeft :size="15" aria-hidden="true" />
      Back to dashboard
    </RouterLink>
  </div>
</template>
