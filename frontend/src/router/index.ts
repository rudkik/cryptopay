import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { useAuthStore } from '@/stores/auth'

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
        path: 'merchants',
        name: 'merchants',
        component: () => import('@/views/admin/MerchantsView.vue'),
        meta: { title: 'Merchants' },
      },
      {
        path: 'merchants/:id',
        name: 'merchant-detail',
        component: () => import('@/views/admin/MerchantDetailView.vue'),
        meta: { title: 'Merchant' },
        props: true,
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
        path: 'networks',
        name: 'networks',
        component: () => import('@/views/admin/NetworksView.vue'),
        meta: { title: 'Networks' },
      },
      {
        path: 'tokens',
        name: 'tokens',
        component: () => import('@/views/admin/TokensView.vue'),
        meta: { title: 'Tokens' },
      },
      {
        path: 'tokens/:id',
        name: 'token-detail',
        component: () => import('@/views/admin/TokenDetailView.vue'),
        meta: { title: 'Token' },
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
      {
        path: 'docs',
        name: 'docs',
        component: () => import('@/views/admin/DocsView.vue'),
        meta: { title: 'Merchant API docs' },
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

  return true
})

router.afterEach((to) => {
  const title = to.meta.title as string | undefined
  document.title = title ? `${title} · CryptoPay` : 'CryptoPay'
})
