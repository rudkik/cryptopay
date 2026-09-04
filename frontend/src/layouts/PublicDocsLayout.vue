<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { BookOpen, Braces, FileJson, LogIn, LayoutDashboard } from 'lucide-vue-next'
import AppLogo from '@/components/AppLogo.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Chrome for the integrator-facing pages (/docs, /swagger). They are public on
 * purpose: the owner shares these links with the teams that connect their
 * projects, so no sign-in is required. Nothing secret is rendered here — the
 * docs are static content and the OpenAPI file is already served publicly.
 */
const route = useRoute()
const auth = useAuthStore()

const links = [
  { label: 'API docs', to: '/docs', icon: BookOpen },
  { label: 'Swagger', to: '/swagger', icon: Braces },
]

const isActive = (to: string) => computed(() => route.path === to || route.path.startsWith(`${to}/`))
</script>

<template>
  <div class="min-h-screen bg-bg text-text">
    <header class="sticky top-0 z-30 border-b border-border bg-surface/95 backdrop-blur">
      <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6">
        <RouterLink to="/docs" class="flex items-center gap-2 font-semibold">
          <AppLogo :size="26" />
          <span>CryptoPay</span>
          <span class="hidden text-muted sm:inline">· Developer docs</span>
        </RouterLink>

        <nav class="ml-auto flex items-center gap-1" aria-label="Documentation">
          <RouterLink
            v-for="link in links"
            :key="link.to"
            :to="link.to"
            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition-colors"
            :class="isActive(link.to).value ? 'bg-primary-soft text-primary' : 'text-muted hover:bg-surface-2 hover:text-text'"
          >
            <component :is="link.icon" :size="15" aria-hidden="true" />
            {{ link.label }}
          </RouterLink>
          <a
            href="/openapi.yaml"
            target="_blank"
            rel="noopener"
            class="hidden items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm text-muted transition-colors hover:bg-surface-2 hover:text-text sm:flex"
          >
            <FileJson :size="15" aria-hidden="true" />
            openapi.yaml
          </a>
          <RouterLink
            v-if="auth.isAuthenticated"
            to="/admin"
            class="ml-2 flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm hover:bg-surface-2"
          >
            <LayoutDashboard :size="15" aria-hidden="true" />
            <span class="hidden sm:inline">Admin</span>
          </RouterLink>
          <RouterLink
            v-else
            to="/login"
            class="ml-2 flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm hover:bg-surface-2"
          >
            <LogIn :size="15" aria-hidden="true" />
            <span class="hidden sm:inline">Sign in</span>
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">
      <RouterView />
    </main>
  </div>
</template>
