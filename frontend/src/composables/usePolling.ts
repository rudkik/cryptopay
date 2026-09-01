import { onScopeDispose, ref } from 'vue'

/**
 * Interval poller that pauses while the tab is hidden and never overlaps runs.
 */
export function usePolling(task: () => Promise<void> | void, intervalMs: number) {
  const active = ref(false)
  let timer: number | undefined
  let running = false

  async function tick(): Promise<void> {
    if (running || document.hidden) return
    running = true
    try {
      await task()
    } finally {
      running = false
    }
  }

  function onVisibility(): void {
    if (!document.hidden && active.value) void tick()
  }

  function start(): void {
    if (active.value) return
    active.value = true
    timer = window.setInterval(() => void tick(), intervalMs)
    document.addEventListener('visibilitychange', onVisibility)
  }

  function stop(): void {
    active.value = false
    if (timer !== undefined) window.clearInterval(timer)
    timer = undefined
    document.removeEventListener('visibilitychange', onVisibility)
  }

  onScopeDispose(stop)

  return { start, stop, active }
}
