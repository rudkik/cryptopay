import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authApi } from '@/api/auth'
import { readStoredToken, writeStoredToken } from '@/api/http'
import type { AdminUser, AppFeatures, MeResponse } from '@/api/types'

function unwrapUser(payload: AdminUser | MeResponse): AdminUser {
  return 'user' in payload ? payload.user : payload
}

/**
 * Optional modules default to off: an older backend that does not send
 * `features` must not light up a module the server will 404 anyway.
 */
function defaultFeatures(): AppFeatures {
  return { token_sale: false }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(readStoredToken())
  const user = ref<AdminUser | null>(null)
  /** Server-side feature flags (SPEC §8) — drive nav visibility and route guards. */
  const features = ref<AppFeatures>(defaultFeatures())
  const loading = ref(false)
  /** True once we've attempted to resolve the session for the stored token. */
  const resolved = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))
  const isAdmin = computed(() => user.value?.role === 'admin')
  const tokenSaleEnabled = computed(() => features.value.token_sale === true)
  const initials = computed(() => {
    const name = user.value?.name?.trim() || user.value?.email || ''
    if (!name) return '—'
    const parts = name.split(/[\s@._-]+/).filter(Boolean)
    return (parts[0]?.[0] ?? '').concat(parts[1]?.[0] ?? '').toUpperCase() || name[0]!.toUpperCase()
  })

  function setSession(nextToken: string, nextUser: AdminUser, nextFeatures?: AppFeatures): void {
    token.value = nextToken
    user.value = nextUser
    features.value = { ...defaultFeatures(), ...(nextFeatures ?? {}) }
    resolved.value = true
    writeStoredToken(nextToken)
  }

  function clear(): void {
    token.value = null
    user.value = null
    features.value = defaultFeatures()
    resolved.value = true
    writeStoredToken(null)
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true
    try {
      const response = await authApi.login(email, password)
      setSession(response.token, response.user, response.features)
    } finally {
      loading.value = false
    }
  }

  /** Resolves the current user for a stored token. Safe to call repeatedly. */
  async function fetchUser(): Promise<AdminUser | null> {
    if (!token.value) {
      resolved.value = true
      return null
    }
    try {
      const payload = await authApi.me()
      user.value = unwrapUser(payload)
      features.value = {
        ...defaultFeatures(),
        ...('features' in payload ? (payload.features ?? {}) : {}),
      }
      return user.value
    } catch {
      // 401 is handled by the axios interceptor; anything else means no session.
      clear()
      return null
    } finally {
      resolved.value = true
    }
  }

  async function logout(): Promise<void> {
    try {
      if (token.value) await authApi.logout()
    } catch {
      /* best effort — the local session is cleared regardless */
    } finally {
      clear()
    }
  }

  return {
    token,
    user,
    features,
    loading,
    resolved,
    isAuthenticated,
    isAdmin,
    tokenSaleEnabled,
    initials,
    login,
    logout,
    fetchUser,
    setSession,
    clear,
  }
})
