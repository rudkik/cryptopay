<script setup lang="ts">
import { computed } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import CopyButton from './CopyButton.vue'
import { truncateMiddle } from '@/utils/format'
import { safeUrl } from '@/utils/url'

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

/**
 * Explorer URLs come from admin-editable network settings and are echoed back by
 * the API; anything that is not plain http(s) is dropped and the value renders
 * as inert text instead of a link.
 */
const linkHref = computed(() => safeUrl(props.href))
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-1">
    <component
      :is="linkHref ? 'a' : 'span'"
      :href="linkHref ?? undefined"
      :target="linkHref ? '_blank' : undefined"
      :rel="linkHref ? 'noopener noreferrer' : undefined"
      class="mono min-w-0 truncate text-muted"
      :class="[
        size === 'sm' ? 'text-xs' : '',
        linkHref ? 'inline-flex items-center gap-1 transition-colors hover:text-primary-hover' : '',
        full ? 'break-all' : '',
      ]"
      :title="value ?? undefined"
      @click.stop
    >
      {{ display }}
      <ExternalLink v-if="linkHref" :size="12" class="shrink-0 opacity-70" aria-hidden="true" />
    </component>
    <CopyButton v-if="copyable && value" :value="value" :label="label" :size="12" />
  </span>
</template>
