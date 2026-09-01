import { reactive, readonly } from 'vue'

export type ToastVariant = 'success' | 'error' | 'warning' | 'info'

export interface ToastItem {
  id: number
  variant: ToastVariant
  title: string
  description?: string
  timeout: number
}

const state = reactive<{ items: ToastItem[] }>({ items: [] })
let nextId = 1

function push(variant: ToastVariant, title: string, description?: string, timeout = 5000): number {
  const id = nextId++
  state.items.push({ id, variant, title, description, timeout })
  if (timeout > 0) {
    window.setTimeout(() => dismiss(id), timeout)
  }
  return id
}

export function dismiss(id: number): void {
  const index = state.items.findIndex((t) => t.id === id)
  if (index !== -1) state.items.splice(index, 1)
}

export const toast = {
  success: (title: string, description?: string) => push('success', title, description),
  error: (title: string, description?: string) => push('error', title, description, 7000),
  warning: (title: string, description?: string) => push('warning', title, description, 6000),
  info: (title: string, description?: string) => push('info', title, description),
  dismiss,
}

export function useToasts() {
  return { toasts: readonly(state).items, dismiss }
}
