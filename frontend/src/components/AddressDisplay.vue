<script setup lang="ts">
import { computed } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import CopyButton from './CopyButton.vue'
import { truncateMiddle } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    value: string | null | undefined
    /** Fully-formed explorer URL, when the backend provides one. */
    href?: string | null
    label?: string
    head?: number
    tail?: number
    /** Show the whole value instead of truncating the middle. */
    full?: boolean
    copyable?: boolean
    size?: 'sm' | 'md'
  }>(),
  { label: 'Address', head: 8, tail: 6, full: false, copyable: true, size: 'md' },
)

const display = computed(() => (props.full ? (props.value ?? '—') : truncateMiddle(props.value, props.head, props.tail)))
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-1">
    <component
      :is="href ? 'a' : 'span'"
      :href="href ?? undefined"
      :target="href ? '_blank' : undefined"
      :rel="href ? 'noopener noreferrer' : undefined"
      class="mono min-w-0 truncate text-muted"
      :class="[
        size === 'sm' ? 'text-xs' : '',
        href ? 'inline-flex items-center gap-1 transition-colors hover:text-primary-hover' : '',
        full ? 'break-all' : '',
      ]"
      :title="value ?? undefined"
      @click.stop
    >
      {{ display }}
      <ExternalLink v-if="href" :size="12" class="shrink-0 opacity-70" aria-hidden="true" />
    </component>
    <CopyButton v-if="copyable && value" :value="value" :label="label" :size="12" />
  </span>
</template>
