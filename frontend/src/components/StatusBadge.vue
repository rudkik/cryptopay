<script setup lang="ts">
import { computed } from 'vue'
import { titleCase } from '@/utils/format'

type Tone = 'muted' | 'warning' | 'success' | 'danger' | 'primary'

const props = withDefaults(
  defineProps<{
    status: string
    /** Extra context for screen readers, e.g. "Invoice status". */
    context?: string
    size?: 'sm' | 'md'
  }>(),
  { size: 'md' },
)

/** SPEC §5 invoice statuses plus transaction / webhook / purchase statuses. */
const TONES: Record<string, Tone> = {
  // invoices
  pending: 'muted',
  confirming: 'warning',
  paid: 'success',
  overpaid: 'success',
  partially_paid: 'warning',
  expired: 'danger',
  cancelled: 'danger',
  // transactions
  detected: 'warning',
  confirmed: 'success',
  failed: 'danger',
  orphaned: 'danger',
  // webhooks / purchases
  delivered: 'success',
  completed: 'success',
  // misc
  active: 'success',
  inactive: 'muted',
  revoked: 'danger',
  healthy: 'success',
  degraded: 'danger',
  disabled: 'muted',
  admin: 'primary',
  viewer: 'muted',
}

const tone = computed<Tone>(() => TONES[props.status] ?? 'muted')

const CLASSES: Record<Tone, { wrap: string; dot: string }> = {
  muted: { wrap: 'border-border bg-surface-2 text-muted', dot: 'bg-muted' },
  warning: { wrap: 'border-warning/30 bg-warning/10 text-warning', dot: 'bg-warning' },
  success: { wrap: 'border-success/30 bg-success/10 text-success', dot: 'bg-success' },
  danger: { wrap: 'border-danger/30 bg-danger/10 text-danger', dot: 'bg-danger' },
  primary: { wrap: 'border-primary/40 bg-primary/15 text-primary-hover', dot: 'bg-primary-hover' },
}

const label = computed(() => titleCase(props.status))
const pulses = computed(() => props.status === 'confirming' || props.status === 'detected')
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium"
    :class="[CLASSES[tone].wrap, size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs']"
  >
    <span class="relative flex h-1.5 w-1.5 shrink-0">
      <span
        v-if="pulses"
        class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
        :class="CLASSES[tone].dot"
      />
      <span class="relative inline-flex h-1.5 w-1.5 rounded-full" :class="CLASSES[tone].dot" />
    </span>
    <span class="sr-only" v-if="context">{{ context }}:</span>
    {{ label }}
  </span>
</template>
