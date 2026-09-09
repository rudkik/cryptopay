import { cleanParams, del, get, postOne, putOne, type QueryParams } from './http'
import type { Currency, NetworkCode, ReceivingAddress } from './types'

export interface ReceivingAddressPayload {
  network: NetworkCode
  address: string
  /** Empty = every currency enabled on the network. */
  currencies: Currency[]
  label?: string | null
  priority?: number
  is_enabled?: boolean
}

/** `network` and `address` are fixed once created; everything else can change. */
export type ReceivingAddressUpdatePayload = Partial<Omit<ReceivingAddressPayload, 'network' | 'address'>>

export interface ReceivingAddressFilters extends QueryParams {
  network?: NetworkCode | ''
}

export const receivingAddressesApi = {
  list: async (params: ReceivingAddressFilters = {}): Promise<ReceivingAddress[]> => {
    const payload = await get<{ data: ReceivingAddress[] }>('/admin/receiving-addresses', {
      params: cleanParams(params),
    })
    return payload.data ?? []
  },

  create: (payload: ReceivingAddressPayload) =>
    postOne<ReceivingAddress>('/admin/receiving-addresses', payload),

  update: (id: string, payload: ReceivingAddressUpdatePayload) =>
    putOne<ReceivingAddress>(`/admin/receiving-addresses/${id}`, payload),

  remove: (id: string) => del<{ data: { deleted: boolean } }>(`/admin/receiving-addresses/${id}`),
}
