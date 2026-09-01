import { cleanParams, del, get, getOne, post, postOne, putOne, type QueryParams } from './http'
import type { ApiKeyCreated, Merchant, Paginated, WebhookSecretRotated } from './types'

export interface MerchantPayload {
  name: string
  email?: string | null
  webhook_url?: string | null
  is_active?: boolean
  settings?: Record<string, unknown> | null
}

export const merchantsApi = {
  list: (params: QueryParams = {}) =>
    get<Paginated<Merchant>>('/admin/merchants', { params: cleanParams(params) }),

  get: (id: string) => getOne<Merchant>(`/admin/merchants/${id}`),

  create: (payload: MerchantPayload) => postOne<Merchant>('/admin/merchants', payload),

  update: (id: string, payload: Partial<MerchantPayload>) =>
    putOne<Merchant>(`/admin/merchants/${id}`, payload),

  createApiKey: (id: string, name: string) =>
    post<ApiKeyCreated>(`/admin/merchants/${id}/api-keys`, { name }),

  revokeApiKey: (id: string, keyId: string) =>
    del<{ ok?: boolean }>(`/admin/merchants/${id}/api-keys/${keyId}`),

  rotateWebhookSecret: (id: string) =>
    post<WebhookSecretRotated>(`/admin/merchants/${id}/webhook-secret/rotate`),
}
