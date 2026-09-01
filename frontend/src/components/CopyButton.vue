<script setup lang="ts">
import { onScopeDispose, ref } from 'vue'
import { Check, Copy } from 'lucide-vue-next'
import { copyText } from '@/utils/clipboard'
import { toast } from '@/utils/toast'

const props = withDefaults(
  defineProps<{
    value: string
    /** Announced + toasted label, e.g. "Address". */
    label?: string
    size?: number
    variant?: 'icon' | 'button'
    text?: string
    /** Show a toast in addition to the inline checkmark. */
    notify?: boolean
  }>(),
  { label: 'Value', size: 14, variant: 'icon', notify: false },
)

const copied = ref(false)
let timer: number | undefined

async function handleCopy(): Promise<void> {
  const ok = await copyText(props.value)
  if (!ok) {
    toast.error('Copy failed', 'Your browser blocked clipboard access.')
    return
  }
  copied.value = true
  if (props.notify) toast.success(`${props.label} copied`)
  window.clearTimeout(timer)
  timer = window.setTimeout(() => (copied.value = false), 1600)
}

onScopeDispose(() => window.clearTimeout(timer))
</script>

<template>
  <button
    type="button"
    :class="
      variant === 'button'
        ? 'btn-secondary btn-sm'
        : 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-muted transition-colors hover:bg-surface-2 hover:text-primary-hover'
    "
    :aria-label="copied ? `${label} copied` : `Copy ${label.toLowerCase()}`"
    :title="`Copy ${label.toLowerCase()}`"
    @click.stop="handleCopy"
  >
    <component
      :is="copied ? Check : Copy"
      :size="size"
      :class="copied ? 'text-success' : ''"
      aria-hidden="true"
    />
    <span v-if="variant === 'button'">{{ copied ? 'Copied' : (text ?? 'Copy') }}</span>
  </button>
</template>
