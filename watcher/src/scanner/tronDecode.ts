import { formatAmount } from '../amount.js';
import { tronHexToBase58 } from '../tronAddress.js';
import type { TokenConfig, TransactionReport } from '../types.js';

/** Селектор transfer(address,uint256). */
export const TRC20_TRANSFER_SELECTOR = 'a9059cbb';

export interface TronContractParameter {
  value?: {
    data?: string;
    owner_address?: string;
    contract_address?: string;
  };
}

export interface TronContract {
  type?: string;
  parameter?: TronContractParameter;
}

export interface TronTransaction {
  txID?: string;
  ret?: { contractRet?: string }[];
  raw_data?: { contract?: TronContract[] };
}

export interface TronBlock {
  blockID?: string;
  block_header?: { raw_data?: { number?: number; parentHash?: string; timestamp?: number } };
  transactions?: TronTransaction[];
}

export interface TronTransfer {
  txHash: string;
  contractHex: string;
  fromHex: string;
  from: string;
  toHex: string;
  to: string;
  valueRaw: bigint;
  blockNumber: number;
  blockHash: string;
}

function stripHexPrefix(value: string): string {
  return value.startsWith('0x') || value.startsWith('0X') ? value.slice(2) : value;
}

export function blockNumberOf(block: TronBlock): number | null {
  const n = block.block_header?.raw_data?.number;
  return typeof n === 'number' ? n : null;
}

/**
 * Разбор данных TriggerSmartContract: `a9059cbb` ‖ to(32) ‖ amount(32).
 * `to = 41 + data[32..72]`, `amount = BigInt('0x' + data[72..136])` (SPEC §7.3).
 */
export function decodeTrc20TransferData(data: string): { toHex: string; valueRaw: bigint } | null {
  const hex = stripHexPrefix(data).toLowerCase();
  if (hex.length < 136) return null;
  if (!hex.startsWith(TRC20_TRANSFER_SELECTOR)) return null;
  const toHex = `41${hex.slice(32, 72)}`;
  if (!/^41[0-9a-f]{40}$/.test(toHex)) return null;
  const amountHex = hex.slice(72, 136);
  if (!/^[0-9a-f]{64}$/.test(amountHex)) return null;
  return { toHex, valueRaw: BigInt(`0x${amountHex}`) };
}

/**
 * Извлекает успешные TRC-20 переводы на отслеживаемые контракты из блока TronGrid.
 * `contracts` — карта hex-адрес контракта (41…, нижний регистр) -> конфиг токена.
 */
export function parseTronBlock(block: TronBlock, contracts: Map<string, TokenConfig>): TronTransfer[] {
  const blockNumber = blockNumberOf(block);
  const blockHash = block.blockID ?? '';
  if (blockNumber === null) return [];

  const out: TronTransfer[] = [];

  for (const tx of block.transactions ?? []) {
    if (tx.ret?.[0]?.contractRet !== 'SUCCESS') continue;

    const contract = tx.raw_data?.contract?.[0];
    if (!contract || contract.type !== 'TriggerSmartContract') continue;

    const value = contract.parameter?.value;
    const contractHex = value?.contract_address ? stripHexPrefix(value.contract_address).toLowerCase() : '';
    if (!contractHex || !contracts.has(contractHex)) continue;

    const data = value?.data;
    if (!data) continue;
    const decoded = decodeTrc20TransferData(data);
    if (!decoded) continue;

    const txHash = tx.txID ?? '';
    if (!txHash) continue;

    const fromHex = stripHexPrefix(value?.owner_address ?? '').toLowerCase();

    let to: string;
    let from: string;
    try {
      to = tronHexToBase58(decoded.toHex);
      from = fromHex ? tronHexToBase58(fromHex) : '';
    } catch {
      continue;
    }

    out.push({
      txHash,
      contractHex,
      fromHex,
      from,
      toHex: decoded.toHex,
      to,
      valueRaw: decoded.valueRaw,
      blockNumber,
      blockHash,
    });
  }

  return out;
}

export function buildTronReport(
  transfer: TronTransfer,
  token: TokenConfig,
  confirmations: number,
): TransactionReport {
  return {
    network: 'tron',
    tx_hash: transfer.txHash,
    log_index: 0,
    contract_address: token.contract_address,
    symbol: token.symbol,
    from_address: transfer.from,
    to_address: transfer.to,
    amount_raw: transfer.valueRaw.toString(),
    amount: formatAmount(transfer.valueRaw, token.decimals),
    block_number: transfer.blockNumber,
    block_hash: transfer.blockHash,
    confirmations,
    status: 'detected',
    raw: {
      contract_hex: transfer.contractHex,
      owner_address_hex: transfer.fromHex,
      to_address_hex: transfer.toHex,
      decimals: token.decimals,
    },
  };
}
