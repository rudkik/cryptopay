<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { X } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    description?: string
    size?: 'sm' | 'md' | 'lg'
    /** Prevent closing via backdrop/Escape (used for one-time secret reveals). */
    persistent?: boolean
  }>(),
  { size: 'md', persistent: false },
)

const emit = defineEmits<{ close: [] }>()

const panel = ref<HTMLElement | null>(null)
const titleId = `modal-title-${Math.random().toString(36).slice(2, 9)}`

const SIZES = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' } as const

function requestClose(): void {
  if (!props.persistent) emit('close')
}

/** Minimal focus trap: keeps Tab inside the dialog while it is open. */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    requestClose()
    return
  }
  if (event.key !== 'Tab' || !panel.value) return

  const focusable = panel.value.querySelectorAll<HTMLElement>(
    'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
  )
  if (focusable.length === 0) return
  const first = focusable[0]!
  const last = focusable[focusable.length - 1]!
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

onBeforeUnmount(() => {
  if (props.open) document.body.style.overflow = ''
})

watch(
  () => props.open,
  async (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
    if (!open) return
    await nextTick()
    const autofocus = panel.value?.querySelector<HTMLElement>('[data-autofocus]')
    ;(autofocus ?? panel.value)?.focus()
  },
  { immediate: true },
)
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-bg/80 p-0 backdrop-blur-sm sm:items-center sm:p-6"
        @click.self="requestClose"
        @keydown="onKeydown"
      >
        <div
          ref="panel"
          class="card-glass animate-slide-up w-full rounded-b-none sm:rounded-2xl"
          :class="SIZES[size]"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
        >
          <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
            <div class="min-w-0">
              <h2 :id="titleId" class="truncate text-base font-semibold text-text">{{ title }}</h2>
              <p v-if="description" class="mt-1 text-sm text-muted">{{ description }}</p>
            </div>
            <button
              v-if="!persistent"
              type="button"
              class="-mr-1 -mt-1 shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-text"
              aria-label="Close dialog"
              @click="emit('close')"
            >
              <X :size="18" aria-hidden="true" />
            </button>
          </header>

          <div class="max-h-[70vh] overflow-y-auto px-5 py-5">
            <slot />
          </div>

          <footer
            v-if="$slots.footer"
            class="flex flex-col-reverse gap-2 border-t border-border px-5 py-4 sm:flex-row sm:justify-end"
          >
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
