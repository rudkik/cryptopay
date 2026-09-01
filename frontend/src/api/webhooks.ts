import { cleanParams, get, postOne, type QueryParams } from './http'
import type { Paginated, WebhookDelivery } from './types'

export interface WebhookFilters extends QueryParams {
  merchant_id?: string
  status?: string
  event?: string
  invoice_id?: string
  page?: number
  per_page?: number
}

export const webhooksApi = {
  list: (params: WebhookFilters = {}) =>
    get<Paginated<WebhookDelivery>>('/admin/webhooks', { params: cleanParams(params) }),

  retry: (id: string) => postOne<WebhookDelivery>(`/admin/webhooks/${id}/retry`),
}
