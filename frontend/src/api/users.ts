import { cleanParams, del, get, postOne, putOne, type QueryParams } from './http'
import type { AdminUser, Paginated, UserRole } from './types'

export interface UserPayload {
  name: string
  email: string
  password?: string
  role: UserRole
  is_active?: boolean
}

export const usersApi = {
  list: (params: QueryParams = {}) =>
    get<Paginated<AdminUser>>('/admin/users', { params: cleanParams(params) }),

  create: (payload: UserPayload) => postOne<AdminUser>('/admin/users', payload),

  update: (id: number | string, payload: Partial<UserPayload>) =>
    putOne<AdminUser>(`/admin/users/${id}`, payload),

  remove: (id: number | string) => del<{ ok?: boolean }>(`/admin/users/${id}`),
}
