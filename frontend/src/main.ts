import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { setUnauthorizedHandler } from './api/http'
import { useAuthStore } from './stores/auth'
import { toast } from './utils/toast'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Wire the axios 401 handler once Pinia exists, keeping api/http.ts dependency-free.
setUnauthorizedHandler(() => {
  const auth = useAuthStore(pinia)
  const wasSignedIn = auth.isAuthenticated
  auth.clear()
  if (router.currentRoute.value.meta.public) return
  if (wasSignedIn) toast.warning('Session expired', 'Please sign in again.')
  void router.push({
    name: 'login',
    query: { redirect: router.currentRoute.value.fullPath },
  })
})

app.mount('#app')
