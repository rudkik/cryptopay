<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeftRight,
  BookOpen,
  Braces,
  ChevronDown,
  Coins,
  FileText,
  LayoutDashboard,
  LogOut,
  Menu,
  Network,
  Store,
  Users,
  Webhook,
  X,
} from 'lucide-vue-next'
import AppLogo from '@/components/AppLogo.vue'
import WatcherHealthMenu from '@/components/WatcherHealthMenu.vue'
import { useAuthStore } from '@/stores/auth'
import { useNetworksStore } from '@/stores/networks'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const networks = useNetworksStore()

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)
const userMenu = ref<HTMLElement | null>(null)

interface NavItem {
  label: string
  to: string
  icon: typeof LayoutDashboard
  exact?: boolean
  adminOnly?: boolean
}

const NAV: { group: string; items: NavItem[] }[] = [
  {
    group: 'Overview',
    items: [{ label: 'Dashboard', to: '/admin', icon: LayoutDashboard, exact: true }],
  },
  {
    group: 'Payments',
    items: [
      { label: 'Invoices', to: '/admin/invoices', icon: FileText },
      { label: 'Transactions', to: '/admin/transactions', icon: ArrowLeftRight },
      { label: 'Webhooks', to: '/admin/webhooks', icon: Webhook },
    ],
  },
  {
    group: 'Catalog',
    items: [
      { label: 'Merchants', to: '/admin/merchants', icon: Store },
      { label: 'Tokens', to: '/admin/tokens', icon: Coins },
    ],
  },
  {
    group: 'System',
    items: [
      { label: 'Networks', to: '/admin/networks', icon: Network },
      { label: 'Admin users', to: '/admin/users', icon: Users, adminOnly: true },
      { label: 'API docs', to: '/admin/docs', icon: BookOpen },
      { label: 'Swagger', to: '/admin/swagger', icon: Braces },
    ],
  },
]

const navigation = computed(() =>
  NAV.map((section) => ({
    ...section,
    items: section.items.filter((item) => !item.adminOnly || auth.isAdmin),
  })).filter((section) => section.items.length > 0),
)

function isActive(item: NavItem): boolean {
  return item.exact ? route.path === item.to : route.path.startsWith(item.to)
}

const pendingUnhealthy = computed(() => networks.unhealthy.length > 0)

async function handleLogout(): Promise<void> {
  userMenuOpen.value = false
  await auth.logout()
  networks.reset()
  void router.push({ name: 'login' })
}

function onDocumentClick(event: MouseEvent): void {
  if (userMenuOpen.value && userMenu.value && !userMenu.value.contains(event.target as Node)) {
    userMenuOpen.value = false
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    sidebarOpen.value = false
    userMenuOpen.value = false
  }
}

watch(() => route.fullPath, () => (sidebarOpen.value = false))

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div class="min-h-screen bg-bg">
    <!-- Mobile scrim -->
    <Transition
      enter-active-class="transition duration-200"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-150"
      leave-to-class="opacity-0"
    >
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-30 bg-bg/80 backdrop-blur-sm lg:hidden"
        @click="sidebarOpen = false"
      />
    </Transition>

    <!-- Sidebar -->
    <aside
      id="admin-sidebar"
      class="fixed inset-y-0 left-0 z-40 flex w-[264px] flex-col border-r border-border bg-surface transition-transform duration-200 ease-out lg:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
      aria-label="Main navigation"
    >
      <div class="flex h-16 shrink-0 items-center justify-between gap-2 px-5">
        <RouterLink to="/admin" class="flex items-center gap-2.5">
          <AppLogo :size="30" />
          <span class="text-[15px] font-semibold tracking-tight">CryptoPay</span>
        </RouterLink>
        <button
          type="button"
          class="rounded-lg p-1.5 text-muted hover:bg-surface-2 hover:text-text lg:hidden"
          aria-label="Close navigation"
          @click="sidebarOpen = false"
        >
          <X :size="18" aria-hidden="true" />
        </button>
      </div>

      <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-6">
        <div v-for="section in navigation" :key="section.group">
          <p class="px-3 pb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-muted/70">
            {{ section.group }}
          </p>
          <ul class="space-y-0.5">
            <li v-for="item in section.items" :key="item.to">
              <RouterLink
                :to="item.to"
                class="group relative flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors"
                :class="
                  isActive(item)
                    ? 'bg-primary/15 text-text'
                    : 'text-muted hover:bg-surface-2 hover:text-text'
                "
                :aria-current="isActive(item) ? 'page' : undefined"
              >
                <span
                  v-if="isActive(item)"
                  class="absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-primary"
                  aria-hidden="true"
                />
                <component
                  :is="item.icon"
                  :size="16"
                  class="shrink-0 transition-colors"
                  :class="isActive(item) ? 'text-primary-hover' : 'text-muted group-hover:text-text'"
                  aria-hidden="true"
                />
                {{ item.label }}
                <span
                  v-if="item.to === '/admin/networks' && pendingUnhealthy"
                  class="ml-auto h-1.5 w-1.5 rounded-full bg-danger"
                  aria-label="Watcher degraded"
                />
              </RouterLink>
            </li>
          </ul>
        </div>
      </nav>

      <div class="shrink-0 border-t border-border px-5 py-3">
        <p class="text-[11px] text-muted">USDT / USDC · ETH · BSC · Tron</p>
      </div>
    </aside>

    <!-- Main column -->
    <div class="lg:pl-[264px]">
      <header
        class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-border bg-bg/85 px-4 backdrop-blur-xl sm:px-6"
      >
        <button
          type="button"
          class="rounded-xl border border-border bg-surface p-2 text-muted transition-colors hover:text-text lg:hidden"
          aria-label="Open navigation"
          aria-controls="admin-sidebar"
          :aria-expanded="sidebarOpen"
          @click.stop="sidebarOpen = true"
        >
          <Menu :size="18" aria-hidden="true" />
        </button>

        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-muted">
            {{ (route.meta.title as string) ?? 'Admin' }}
          </p>
        </div>

        <WatcherHealthMenu />

        <div ref="userMenu" class="relative">
          <button
            type="button"
            class="flex items-center gap-2 rounded-xl border border-border bg-surface py-1.5 pl-1.5 pr-2.5 transition-colors hover:border-primary/40"
            :aria-expanded="userMenuOpen"
            aria-haspopup="menu"
            @click="userMenuOpen = !userMenuOpen"
          >
            <span
              class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-accent text-[11px] font-semibold text-white"
              aria-hidden="true"
            >
              {{ auth.initials }}
            </span>
            <span class="hidden max-w-[140px] truncate text-sm sm:block">
              {{ auth.user?.name ?? 'Account' }}
            </span>
            <ChevronDown :size="14" class="text-muted" aria-hidden="true" />
          </button>

          <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
          >
            <div
              v-if="userMenuOpen"
              class="card-glass absolute right-0 mt-2 w-60 p-1.5"
              role="menu"
              aria-label="Account"
            >
              <div class="border-b border-border px-3 py-2.5">
                <p class="truncate text-sm font-medium">{{ auth.user?.name ?? '—' }}</p>
                <p class="truncate text-xs text-muted">{{ auth.user?.email ?? '' }}</p>
                <p class="mt-1.5 text-[11px] uppercase tracking-wide text-primary-hover">
                  {{ auth.user?.role ?? 'viewer' }}
                </p>
              </div>
              <button
                type="button"
                role="menuitem"
                class="mt-1 flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-muted transition-colors hover:bg-surface-2 hover:text-danger"
                @click="handleLogout"
              >
                <LogOut :size="15" aria-hidden="true" />
                Sign out
              </button>
            </div>
          </Transition>
        </div>
      </header>

      <main class="mx-auto w-full max-w-[1400px] px-4 py-6 sm:px-6 sm:py-8">
        <RouterView v-slot="{ Component, route: current }">
          <Transition
            mode="out-in"
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
          >
            <component :is="Component" :key="current.path" />
          </Transition>
        </RouterView>
      </main>
    </div>
  </div>
</template>
