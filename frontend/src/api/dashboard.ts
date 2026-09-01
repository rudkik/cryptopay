import { get } from './http'
import type { DashboardData, WatcherHealth } from './types'

export const dashboardApi = {
  fetch: () => get<DashboardData>('/admin/dashboard'),
  watcherHealth: () => get<WatcherHealth>('/admin/watcher/health'),
}
