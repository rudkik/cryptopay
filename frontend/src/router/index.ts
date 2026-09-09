import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { useAuthStore } from '@/stores/auth'
import type { AppFeatures } from '@/api/types'
import { toast } from '@/utils/toast'

/** Message shown when a route is gated behind a disabled server feature. */
const FEATURE_DISABLED: Record<keyof AppFeatures, string> = {
  token_sale: 'Token sale module is disabled',
}

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true, title: 'Sign in' },
  },
  {
    path: '/pay/:id',
    name: 'checkout',
    component: () => import('@/views/public/CheckoutView.vue'),
    meta: { public: true, title: 'Checkout' },
    props: true,
  },
  {
    path: '/admin',
    component: AdminLayout,
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('@/views/admin/DashboardView.vue'),
        meta: { title: 'Dashboard' },
      },
      {
        // "Service" is the admin-side name for what the API still calls a
        // merchant (SPEC §9): one connected project with its API keys,
        // webhook URL and balances. Only the UI vocabulary changed.
        path: 'services',
        name: 'services',
        component: () => import('@/views/admin/ServicesView.vue'),
        meta: { title: 'Services' },
      },
      {
        path: 'services/:id',
        name: 'service-detail',
        component: () => import('@/views/admin/ServiceDetailView.vue'),
        meta: { title: 'Service' },
        props: true,
      },
      // Bookmarks and links from before the rename keep working.
      { path: 'merchants', redirect: { name: 'services' } },
      {
        path: 'merchants/:id',
        redirect: (to) => ({ name: 'service-detail', params: { id: to.params.id } }),
      },
      {
        path: 'invoices',
        name: 'invoices',
        component: () => import('@/views/admin/InvoicesView.vue'),
        meta: { title: 'Invoices' },
      },
      {
        path: 'invoices/:id',
        name: 'invoice-detail',
        component: () => import('@/views/admin/InvoiceDetailView.vue'),
        meta: { title: 'Invoice' },
        props: true,
      },
      {
        path: 'transactions',
        name: 'transactions',
        component: () => import('@/views/admin/TransactionsView.vue'),
        meta: { title: 'Transactions' },
      },
      {
        path: 'wallet',
        name: 'wallet',
        component: () => import('@/views/admin/WalletView.vue'),
        meta: { title: 'Wallet' },
      },
      {
        path: 'addresses',
        name: 'addresses',
        component: () => import('@/views/admin/AddressesView.vue'),
        meta: { title: 'Addresses' },
      },
      {
        path: 'networks',
        name: 'networks',
        component: () => import('@/views/admin/NetworksView.vue'),
        meta: { title: 'Networks' },
      },
      {
        path: 'tokens',
        name: 'tokens',
        component: () => import('@/views/admin/TokensView.vue'),
        meta: { title: 'Tokens', feature: 'token_sale' },
      },
      {
        path: 'tokens/:id',
        name: 'token-detail',
        component: () => import('@/views/admin/TokenDetailView.vue'),
        meta: { title: 'Token', feature: 'token_sale' },
        props: true,
      },
      {
        path: 'webhooks',
        name: 'webhooks',
        component: () => import('@/views/admin/WebhooksView.vue'),
        meta: { title: 'Webhook deliveries' },
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('@/views/admin/UsersView.vue'),
        meta: { title: 'Admin users', adminOnly: true },
      },
      // Docs and Swagger are public (see the /docs routes below); keep the old
      // admin paths working for bookmarks.
      { path: 'docs', redirect: '/docs' },
      { path: 'swagger', redirect: '/swagger' },
    ],
  },
  {
    // Integrator-facing documentation: intentionally reachable without sign-in
    // so the links can be handed to the teams connecting their projects.
    path: '/',
    component: () => import('@/layouts/PublicDocsLayout.vue'),
    children: [
      {
        path: 'docs',
        name: 'docs',
        component: () => import('@/views/admin/DocsView.vue'),
        meta: { public: true, title: 'Merchant API docs' },
      },
      {
        // Separate chunk on purpose: the ~1.5 MB swagger-ui bundle and its CSS
        // must not be paid for by the rest of the app.
        path: 'swagger',
        name: 'swagger',
        component: () => import('@/views/admin/SwaggerView.vue'),
        meta: { public: true, title: 'Swagger' },
      },
    ],
  },
  { path: '/', redirect: '/admin' },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { public: true, title: 'Not found' },
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, from, saved) {
    if (saved) return saved
    if (to.path === from.path) return
    return { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.public) {
    // A signed-in admin landing on /login goes straight to the dashboard.
    if (to.name === 'login' && auth.isAuthenticated) {
      if (!auth.resolved) await auth.fetchUser()
      if (auth.isAuthenticated) return { path: '/admin' }
    }
    return true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath === '/admin' ? {} : { redirect: to.fullPath } }
  }

  if (!auth.resolved) {
    await auth.fetchUser()
    if (!auth.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  if (to.meta.adminOnly && !auth.isAdmin) return { path: '/admin' }

  // Optional modules (SPEC §8): the API 404s them when the flag is off, so the
  // screens must not be reachable either — `features` is resolved by now.
  const feature = to.meta.feature as keyof AppFeatures | undefined
  if (feature && !auth.features[feature]) {
    toast.error(FEATURE_DISABLED[feature])
    return { path: '/admin' }
  }

  return true
})

router.afterEach((to) => {
  const title = to.meta.title as string | undefined
  document.title = title ? `${title} · CryptoPay` : 'CryptoPay'
})
