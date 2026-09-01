import { cleanParams, get, getOne, postOne, type QueryParams } from './http'
import type { Invoice, Paginated, PublicInvoice } from './types'

export interface InvoiceFilters extends QueryParams {
  status?: string
  network?: string
  currency?: string
  merchant_id?: string
  q?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}

export interface SimulatePaymentPayload {
  amount?: string
  confirmed?: boolean
}

export const invoicesApi = {
  list: (params: InvoiceFilters = {}) =>
    get<Paginated<Invoice>>('/admin/invoices', { params: cleanParams(params) }),

  get: (id: string) => getOne<Invoice>(`/admin/invoices/${id}`),

  cancel: (id: string) => postOne<Invoice>(`/admin/invoices/${id}/cancel`),

  simulatePayment: (id: string, payload: SimulatePaymentPayload) =>
    postOne<Invoice>(`/admin/invoices/${id}/simulate-payment`, payload),
}

export const publicInvoicesApi = {
  get: (id: string) => getOne<PublicInvoice>(`/public/invoices/${id}`),
}
