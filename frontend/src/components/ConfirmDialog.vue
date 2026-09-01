<script setup lang="ts">
import Modal from './Modal.vue'
import Spinner from './Spinner.vue'

withDefaults(
  defineProps<{
    open: boolean
    title: string
    message: string
    confirmLabel?: string
    cancelLabel?: string
    tone?: 'primary' | 'danger'
    loading?: boolean
  }>(),
  { confirmLabel: 'Confirm', cancelLabel: 'Cancel', tone: 'primary', loading: false },
)

const emit = defineEmits<{ close: []; confirm: [] }>()
</script>

<template>
  <Modal :open="open" :title="title" size="sm" @close="emit('close')">
    <p class="text-sm text-muted">{{ message }}</p>
    <template #footer>
      <button type="button" class="btn-secondary" @click="emit('close')">{{ cancelLabel }}</button>
      <button
        type="button"
        :class="tone === 'danger' ? 'btn-danger' : 'btn-primary'"
        :disabled="loading"
        data-autofocus
        @click="emit('confirm')"
      >
        <Spinner v-if="loading" :size="14" />
        {{ confirmLabel }}
      </button>
    </template>
  </Modal>
</template>
