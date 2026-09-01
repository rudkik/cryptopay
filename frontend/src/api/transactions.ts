import { cleanParams, get, getOne, type QueryParams } from './http'
import type { Paginated, Transaction } from './types'

export interface TransactionFilters extends QueryParams {
  invoice_id?: string
  merchant_id?: string
  network?: string
  status?: string
  currency?: string
  q?: string
  page?: number
  per_page?: number
}

export const transactionsApi = {
  list: (params: TransactionFilters = {}) =>
    get<Paginated<Transaction>>('/admin/transactions', { params: cleanParams(params) }),

  get: (id: string) => getOne<Transaction>(`/admin/transactions/${id}`),
}
