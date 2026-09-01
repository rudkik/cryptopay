import { cleanParams, get, type QueryParams } from './http'
import type { Balance, BalanceTotals, LedgerEntry, Paginated } from './types'

export const balancesApi = {
  list: (params: QueryParams = {}) =>
    get<{ data: Balance[]; totals?: BalanceTotals[] } | Balance[]>('/admin/balances', {
      params: cleanParams(params),
    }),

  ledger: (params: QueryParams = {}) =>
    get<Paginated<LedgerEntry>>('/admin/ledger', { params: cleanParams(params) }),
}
