import { cleanParams, del, get, post, putOne, unwrap, type QueryParams } from './http'
import type {
  DerivedAddressPreview,
  IssuedAddress,
  NetworkCode,
  Paginated,
  WalletItem,
} from './types'

export interface WalletUpdatePayload {
  /** Account-level extended public key. Sent once, never stored client-side. */
  xpub: string
  label?: string | null
  /** Ethereum and BSC share one EVM key (SPEC §3) — write both in one call. */
  apply_to_evm?: boolean
}

export interface IssuedAddressFilters extends QueryParams {
  page?: number
  per_page?: number
}

export const walletsApi = {
  list: async (): Promise<WalletItem[]> => {
    const payload = await get<{ data: WalletItem[] }>('/admin/wallets')
    return payload.data ?? []
  },

  /**
   * Derives the first five addresses so the owner can compare them with their
   * own wallet app. The response is a bare `{ addresses }` object, not the
   * `{ data }` resource envelope, and nothing is written server-side.
   */
  preview: async (network: NetworkCode, xpub: string): Promise<DerivedAddressPreview[]> => {
    const payload = await post<{ addresses: DerivedAddressPreview[] }>(
      `/admin/wallets/${network}/preview`,
      { xpub },
    )
    return payload.addresses ?? []
  },

  update: (network: NetworkCode, payload: WalletUpdatePayload) =>
    putOne<WalletItem>(`/admin/wallets/${network}`, payload),

  /** Clears the stored key; derivation falls back to the watcher's env key. */
  removeXpub: async (network: NetworkCode): Promise<WalletItem> =>
    unwrap<WalletItem>(await del<{ data: WalletItem }>(`/admin/wallets/${network}/xpub`)),

  addresses: (network: NetworkCode, params: IssuedAddressFilters = {}) =>
    get<Paginated<IssuedAddress>>(`/admin/wallets/${network}/addresses`, {
      params: cleanParams(params),
    }),
}
