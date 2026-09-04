import { get, post } from './http'
import type { AdminUser, LoginResponse, MeResponse, PublicConfig } from './types'

export const authApi = {
  login: (email: string, password: string) =>
    post<LoginResponse>('/admin/auth/login', { email, password }),

  logout: () => post<{ ok?: boolean }>('/admin/auth/logout'),

  me: () => get<AdminUser | MeResponse>('/admin/auth/me'),

  /** Unauthenticated feature/network probe (SPEC §6.3). */
  publicConfig: () => get<PublicConfig>('/public/config'),
}
