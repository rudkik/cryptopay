import { cleanParams, del, get, getOne, postOne, putOne, type QueryParams } from './http'
import type { Paginated, Token, TokenHolding, TokenPurchase } from './types'

export interface TokenPayload {
  merchant_id: string
  symbol: string
  name: string
  description?: string | null
  price_usd: string
  decimals?: number
  total_supply?: string | null
  min_purchase?: string | null
  max_purchase?: string | null
  is_active?: boolean
  image_url?: string | null
}

export const tokensApi = {
  list: (params: QueryParams = {}) =>
    get<Paginated<Token>>('/admin/tokens', { params: cleanParams(params) }),

  get: (id: string) => getOne<Token>(`/admin/tokens/${id}`),

  create: (payload: TokenPayload) => postOne<Token>('/admin/tokens', payload),

  update: (id: string, payload: Partial<TokenPayload>) =>
    putOne<Token>(`/admin/tokens/${id}`, payload),

  remove: (id: string) => del<{ ok?: boolean }>(`/admin/tokens/${id}`),

  holdings: (id: string, params: QueryParams = {}) =>
    get<Paginated<TokenHolding> | { data: TokenHolding[] }>(`/admin/tokens/${id}/holdings`, {
      params: cleanParams(params),
    }),

  purchases: (params: QueryParams = {}) =>
    get<Paginated<TokenPurchase>>('/admin/token-purchases', { params: cleanParams(params) }),
}
