import { computed, onScopeDispose, ref, watch, type Ref } from 'vue'
import { formatDuration } from '@/utils/format'

/** Live countdown to an ISO timestamp. Ticks once per second. */
export function useCountdown(target: Ref<string | null | undefined>) {
  const now = ref(Date.now())
  const timer = window.setInterval(() => {
    now.value = Date.now()
  }, 1000)

  onScopeDispose(() => window.clearInterval(timer))
  watch(target, () => {
    now.value = Date.now()
  })

  const targetMs = computed(() => {
    if (!target.value) return null
    const ms = new Date(target.value).getTime()
    return Number.isNaN(ms) ? null : ms
  })

  const secondsLeft = computed(() => {
    if (targetMs.value === null) return null
    return Math.max(0, Math.floor((targetMs.value - now.value) / 1000))
  })

  const expired = computed(() => secondsLeft.value !== null && secondsLeft.value <= 0)
  const label = computed(() => (secondsLeft.value === null ? '—' : formatDuration(secondsLeft.value)))
  /** True in the final 5 minutes — used to switch the timer to a warning colour. */
  const urgent = computed(() => secondsLeft.value !== null && secondsLeft.value > 0 && secondsLeft.value <= 300)

  return { secondsLeft, expired, label, urgent }
}
