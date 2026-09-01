/** Общие типы контракта watcher <-> backend (SPEC §6.5, §6.6). */

export type NetworkCode = 'ethereum' | 'bsc' | 'tron';

export type TxStatus = 'detected' | 'confirmed' | 'orphaned';

export interface TokenConfig {
  symbol: string;
  contract_address: string;
  decimals: number;
}

export interface NetworkConfig {
  code: NetworkCode;
  chain_id: number | null;
  confirmations_required: number;
  is_enabled: boolean;
  last_scanned_block: number | null;
  tokens: TokenConfig[];
}

export interface BackendConfig {
  networks: NetworkConfig[];
}

export interface WatchAddress {
  id: string;
  address: string;
}

/** Тело POST /api/internal/transactions (SPEC §6.5). */
export interface TransactionReport {
  network: NetworkCode;
  tx_hash: string;
  log_index: number;
  contract_address: string;
  symbol: string;
  from_address: string;
  to_address: string;
  amount_raw: string;
  amount: string;
  block_number: number;
  block_hash: string;
  confirmations: number;
  status: TxStatus;
  raw: Record<string, unknown>;
}

export interface HeartbeatReport {
  network: NetworkCode;
  last_scanned_block: number | null;
  head_block: number | null;
  healthy: boolean;
  error: string | null;
}

/** Элемент ответа GET /health (SPEC §6.6). */
export interface NetworkHealth {
  code: NetworkCode;
  enabled: boolean;
  headBlock: number | null;
  lastScannedBlock: number | null;
  lag: number | null;
  pendingTxs: number;
  lastError: string | null;
  updatedAt: string | null;
}

export const EVM_NETWORKS: readonly NetworkCode[] = ['ethereum', 'bsc'] as const;
export const ALL_NETWORKS: readonly NetworkCode[] = ['ethereum', 'bsc', 'tron'] as const;

export function isNetworkCode(value: unknown): value is NetworkCode {
  return value === 'ethereum' || value === 'bsc' || value === 'tron';
}
