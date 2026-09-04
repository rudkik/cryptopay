<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next'
import CopyButton from './CopyButton.vue'
import Modal from './Modal.vue'

defineProps<{
  open: boolean
  title: string
  description: string
  secret: string
  /** Label used in the copy button and the announcement. */
  secretLabel: string
}>()

const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <!-- Persistent: the value can never be retrieved again, so closing is deliberate. -->
  <Modal :open="open" :title="title" size="md" persistent @close="emit('close')">
    <div class="space-y-4">
      <p
        class="flex items-start gap-2.5 rounded-xl border border-warning/25 bg-warning-soft px-3.5 py-3 text-xs leading-relaxed text-warning"
      >
        <AlertTriangle :size="15" class="mt-px shrink-0" aria-hidden="true" />
        <span>{{ description }}</span>
      </p>

      <div>
        <p class="label">{{ secretLabel }}</p>
        <div class="flex items-start gap-2 rounded-xl border border-border bg-surface-2 px-3 py-3">
          <code class="mono min-w-0 flex-1 break-all text-[12.5px] text-text">{{ secret }}</code>
          <CopyButton :value="secret" :label="secretLabel" :size="15" notify />
        </div>
      </div>
    </div>

    <template #footer>
      <button type="button" class="btn-primary" data-autofocus @click="emit('close')">
        I have stored it securely
      </button>
    </template>
  </Modal>
</template>
