import type { Currency, NetworkCode } from '@/api/types'

export interface Option {
  value: string
  label: string
}

export const INVOICE_STATUS_OPTIONS: Option[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'confirming', label: 'Confirming' },
  { value: 'paid', label: 'Paid' },
  { value: 'overpaid', label: 'Overpaid' },
  { value: 'partially_paid', label: 'Partially paid' },
  { value: 'expired', label: 'Expired' },
  { value: 'cancelled', label: 'Cancelled' },
]

export const TRANSACTION_STATUS_OPTIONS: Option[] = [
  { value: 'detected', label: 'Detected' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'failed', label: 'Failed' },
  { value: 'orphaned', label: 'Orphaned' },
]

export const WEBHOOK_STATUS_OPTIONS: Option[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'failed', label: 'Failed' },
]

export const NETWORK_OPTIONS: Option[] = [
  { value: 'ethereum', label: 'Ethereum' },
  { value: 'bsc', label: 'BNB Smart Chain' },
  { value: 'tron', label: 'Tron' },
]

export const CURRENCY_OPTIONS: Option[] = [
  { value: 'USDT', label: 'USDT' },
  { value: 'USDC', label: 'USDC' },
]

export const NETWORK_NAMES: Record<string, string> = {
  ethereum: 'Ethereum',
  bsc: 'BNB Smart Chain',
  tron: 'Tron',
}

export const CURRENCIES: Currency[] = ['USDT', 'USDC']
export const NETWORK_CODES: NetworkCode[] = ['ethereum', 'bsc', 'tron']
