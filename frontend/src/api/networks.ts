import { get, putOne } from './http'
import type { Currency, Network, NetworkCode, TokenContract } from './types'

export interface NetworkUpdatePayload {
  confirmations_required?: number
  is_enabled?: boolean
  explorer_tx_url?: string | null
  explorer_address_url?: string | null
}

export interface TokenContractUpdatePayload {
  contract_address?: string
  decimals?: number
  is_enabled?: boolean
}

export const networksApi = {
  list: () => get<Network[] | { data: Network[] }>('/admin/networks'),

  update: (code: NetworkCode, payload: NetworkUpdatePayload) =>
    putOne<Network>(`/admin/networks/${code}`, payload),

  updateToken: (code: NetworkCode, symbol: Currency, payload: TokenContractUpdatePayload) =>
    putOne<TokenContract>(`/admin/networks/${code}/tokens/${symbol}`, payload),
}
