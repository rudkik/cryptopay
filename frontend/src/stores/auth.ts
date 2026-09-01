import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authApi } from '@/api/auth'
import { readStoredToken, writeStoredToken } from '@/api/http'
import type { AdminUser } from '@/api/types'

function unwrapUser(payload: AdminUser | { user: AdminUser }): AdminUser {
  return 'user' in payload ? payload.user : payload
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(readStoredToken())
  const user = ref<AdminUser | null>(null)
  const loading = ref(false)
  /** True once we've attempted to resolve the session for the stored token. */
  const resolved = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))
  const isAdmin = computed(() => user.value?.role === 'admin')
  const initials = computed(() => {
    const name = user.value?.name?.trim() || user.value?.email || ''
    if (!name) return '—'
    const parts = name.split(/[\s@._-]+/).filter(Boolean)
    return (parts[0]?.[0] ?? '').concat(parts[1]?.[0] ?? '').toUpperCase() || name[0]!.toUpperCase()
  })

  function setSession(nextToken: string, nextUser: AdminUser): void {
    token.value = nextToken
    user.value = nextUser
    resolved.value = true
    writeStoredToken(nextToken)
  }

  function clear(): void {
    token.value = null
    user.value = null
    resolved.value = true
    writeStoredToken(null)
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true
    try {
      const response = await authApi.login(email, password)
      setSession(response.token, response.user)
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
    loading,
    resolved,
    isAuthenticated,
    isAdmin,
    initials,
    login,
    logout,
    fetchUser,
    setSession,
    clear,
  }
})
