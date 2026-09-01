import { get, post } from './http'
import type { AdminUser, LoginResponse } from './types'

export const authApi = {
  login: (email: string, password: string) =>
    post<LoginResponse>('/admin/auth/login', { email, password }),

  logout: () => post<{ ok?: boolean }>('/admin/auth/logout'),

  me: () => get<AdminUser | { user: AdminUser }>('/admin/auth/me'),
}
