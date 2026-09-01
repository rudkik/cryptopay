<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-vue-next'
import { useToasts, type ToastVariant } from '@/utils/toast'

const { toasts, dismiss } = useToasts()

const ICONS = {
  success: CheckCircle2,
  error: XCircle,
  warning: AlertTriangle,
  info: Info,
} as const

const TONES: Record<ToastVariant, string> = {
  success: 'text-success',
  error: 'text-danger',
  warning: 'text-warning',
  info: 'text-primary-hover',
}
</script>

<template>
  <Teleport to="body">
    <div
      class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:inset-x-auto sm:right-0 sm:top-0 sm:items-end"
      role="region"
      aria-label="Notifications"
    >
      <TransitionGroup
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 translate-y-2 sm:translate-x-3 sm:translate-y-0"
        leave-active-class="transition duration-150 ease-in absolute"
        leave-to-class="opacity-0 scale-95"
        move-class="transition duration-200"
      >
        <div
          v-for="item in toasts"
          :key="item.id"
          class="card-glass pointer-events-auto flex w-full max-w-sm items-start gap-3 p-3.5 shadow-2xl"
          role="alert"
          :aria-live="item.variant === 'error' ? 'assertive' : 'polite'"
        >
          <component
            :is="ICONS[item.variant]"
            :size="18"
            class="mt-0.5 shrink-0"
            :class="TONES[item.variant]"
            aria-hidden="true"
          />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-text">{{ item.title }}</p>
            <p v-if="item.description" class="mt-0.5 break-words text-xs text-muted">
              {{ item.description }}
            </p>
          </div>
          <button
            type="button"
            class="-mr-1 -mt-1 shrink-0 rounded-lg p-1 text-muted transition-colors hover:bg-surface-2 hover:text-text"
            aria-label="Dismiss notification"
            @click="dismiss(item.id)"
          >
            <X :size="14" aria-hidden="true" />
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
