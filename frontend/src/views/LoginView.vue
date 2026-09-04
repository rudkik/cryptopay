<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Eye, EyeOff, Lock, Mail } from 'lucide-vue-next'
import AppLogo from '@/components/AppLogo.vue'
import Spinner from '@/components/Spinner.vue'
import { useAuthStore } from '@/stores/auth'
import { errorMessage, fieldErrors } from '@/composables/useErrorHandler'
import { isApiError } from '@/api/http'
import { safeInternalPath } from '@/utils/url'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const formError = ref('')
const errors = ref<Record<string, string>>({})

/**
 * Only same-origin paths are honoured. A bare `startsWith('/')` check would let
 * `//evil.example` (protocol-relative) and `/\evil.example` through as an open
 * redirect off a page that has just accepted admin credentials.
 */
const redirect = computed(() => safeInternalPath(route.query.redirect, '/admin'))

async function submit(): Promise<void> {
  if (submitting.value) return
  submitting.value = true
  formError.value = ''
  errors.value = {}
  try {
    await auth.login(email.value.trim(), password.value)
    await router.replace(redirect.value)
  } catch (error) {
    const fields = fieldErrors(error)
    errors.value = fields
    // With a field message present the banner would only repeat Laravel's
    // "The given data was invalid." — which says nothing, and buries the real
    // reason ("This account is disabled.") in small print underneath.
    formError.value =
      isApiError(error) && error.status === 401
        ? 'Incorrect email or password.'
        : Object.keys(fields).length > 0
          ? ''
          : errorMessage(error, 'Sign-in failed. Please try again.')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  if (auth.isAuthenticated) void router.replace('/admin')
})
</script>

<template>
  <div class="glow-bg relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10">
    <div class="grid-lines pointer-events-none absolute inset-0 opacity-70" aria-hidden="true" />
    <div
      class="pointer-events-none absolute left-1/2 top-0 h-px w-[560px] max-w-full -translate-x-1/2 bg-gradient-to-r from-transparent via-primary/40 to-transparent"
      aria-hidden="true"
    />

    <div class="relative w-full max-w-[400px]">
      <div class="mb-8 flex flex-col items-center text-center">
        <AppLogo :size="44" />
        <h1 class="mt-4 text-2xl font-semibold tracking-tight">CryptoPay</h1>
        <p class="mt-1.5 text-sm text-muted">
          Stablecoin payment processing — Ethereum, BSC and Tron.
        </p>
      </div>

      <div class="card-glass p-6 sm:p-7">
        <h2 class="text-base font-semibold">Sign in to the console</h2>
        <p class="mt-1 text-sm text-muted">Administrator access only.</p>

        <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
          <div
            v-if="formError"
            class="rounded-xl border border-danger/25 bg-danger-soft px-3.5 py-2.5 text-sm text-danger"
            role="alert"
          >
            {{ formError }}
          </div>

          <div>
            <label for="email" class="label">Email</label>
            <div class="relative">
              <Mail
                :size="15"
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
                aria-hidden="true"
              />
              <input
                id="email"
                v-model="email"
                type="email"
                name="email"
                autocomplete="username"
                required
                class="input pl-9"
                :class="errors.email ? 'input-error' : ''"
                :aria-invalid="Boolean(errors.email)"
                :aria-describedby="errors.email ? 'email-error' : undefined"
                placeholder="admin@cryptopay.local"
              />
            </div>
            <p v-if="errors.email" id="email-error" class="error-text">{{ errors.email }}</p>
          </div>

          <div>
            <label for="password" class="label">Password</label>
            <div class="relative">
              <Lock
                :size="15"
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
                aria-hidden="true"
              />
              <input
                id="password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                autocomplete="current-password"
                required
                class="input px-9"
                :class="errors.password ? 'input-error' : ''"
                :aria-invalid="Boolean(errors.password)"
                :aria-describedby="errors.password ? 'password-error' : undefined"
                placeholder="••••••••"
              />
              <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-muted transition-colors hover:text-text"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                @click="showPassword = !showPassword"
              >
                <component :is="showPassword ? EyeOff : Eye" :size="15" aria-hidden="true" />
              </button>
            </div>
            <p v-if="errors.password" id="password-error" class="error-text">{{ errors.password }}</p>
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="submitting">
            <Spinner v-if="submitting" :size="15" />
            <template v-else>
              Sign in
              <ArrowRight :size="15" aria-hidden="true" />
            </template>
          </button>
        </form>
      </div>

      <p class="mt-6 text-center text-xs text-muted">
        Protected console · all actions are recorded in the audit log
      </p>
    </div>
  </div>
</template>
