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
  // receiving addresses
  free: 'success',
  busy: 'warning',
  admin: 'primary',
  viewer: 'muted',
}

const tone = computed<Tone>(() => TONES[props.status] ?? 'muted')

/** Soft tint + darker ink of the same hue: every pair clears WCAG AA. */
const CLASSES: Record<Tone, { wrap: string; dot: string }> = {
  muted: { wrap: 'border-border-strong bg-surface-2 text-muted', dot: 'bg-muted' },
  warning: { wrap: 'border-warning/25 bg-warning-soft text-warning', dot: 'bg-warning' },
  success: { wrap: 'border-success/25 bg-success-soft text-success', dot: 'bg-success' },
  danger: { wrap: 'border-danger/25 bg-danger-soft text-danger', dot: 'bg-danger' },
  primary: { wrap: 'border-primary/25 bg-primary-soft text-primary-hover', dot: 'bg-primary' },
}

const label = computed(() => titleCase(props.status))
const pulses = computed(() => props.status === 'confirming' || props.status === 'detected')
</script>

<template>
  <!--
    `relative` is load-bearing, not decoration: the `.sr-only` span below is
    `position:absolute`, so without a positioned ancestor it resolves against
    the initial containing block. Inside a horizontally scrolled table that
    placed it at the table's own x-offset, which widened the *document* and
    gave every narrow viewport a phantom horizontal scrollbar.
  -->
  <span
    class="relative inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-medium"
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
