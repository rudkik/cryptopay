<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { X } from 'lucide-vue-next'

const props = defineProps<{ open: boolean; title: string }>()
const emit = defineEmits<{ close: [] }>()

watch(
  () => props.open,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
  },
)

onBeforeUnmount(() => {
  if (props.open) document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-50 bg-text/35 backdrop-blur-[2px]"
        @click.self="emit('close')"
        @keydown.esc="emit('close')"
      >
        <aside
          class="animate-slide-in-right absolute inset-y-0 right-0 flex w-full max-w-md flex-col border-l border-border bg-surface shadow-pop"
          role="dialog"
          aria-modal="true"
          :aria-label="title"
        >
          <header class="flex items-center justify-between gap-4 border-b border-border px-5 py-4">
            <h2 class="truncate text-base font-semibold">{{ title }}</h2>
            <button
              type="button"
              class="rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-text"
              aria-label="Close panel"
              @click="emit('close')"
            >
              <X :size="18" aria-hidden="true" />
            </button>
          </header>
          <div class="flex-1 overflow-y-auto px-5 py-5">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="border-t border-border px-5 py-4">
            <slot name="footer" />
          </footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>
