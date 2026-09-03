import { formatAmount } from '../amount.js';
import { tronHexToBase58 } from '../tronAddress.js';
import type { TokenConfig, TransactionReport } from '../types.js';

/** Селектор transfer(address,uint256). */
export const TRC20_TRANSFER_SELECTOR = 'a9059cbb';
/** Селектор transferFrom(address,address,uint256). */
export const TRC20_TRANSFER_FROM_SELECTOR = '23b872dd';

/** 4 байта селектора + 2 слова по 32 байта. */
const TRANSFER_DATA_LEN = 8 + 64 * 2;
/** 4 байта селектора + 3 слова по 32 байта. */
const TRANSFER_FROM_DATA_LEN = 8 + 64 * 3;

const HEX64 = /^[0-9a-f]{64}$/;
const TRON_HEX_ADDRESS = /^41[0-9a-f]{40}$/;

export type TrcTransferMethod = 'transfer' | 'transferFrom';

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
  /** Отправитель средств: owner для transfer, параметр `from` для transferFrom. */
  fromHex: string;
  from: string;
  toHex: string;
  to: string;
  /** msg.sender транзакции (для transferFrom это spender, а не владелец средств). */
  ownerHex: string;
  method: TrcTransferMethod;
  valueRaw: bigint;
  blockNumber: number;
  blockHash: string;
}

export interface DecodedTrcTransfer {
  method: TrcTransferMethod;
  toHex: string;
  /** Заполняется только для transferFrom. */
  fromHex: string | null;
  valueRaw: bigint;
}

function stripHexPrefix(value: string): string {
  return value.startsWith('0x') || value.startsWith('0X') ? value.slice(2) : value;
}

export function blockNumberOf(block: TronBlock): number | null {
  const n = block?.block_header?.raw_data?.number;
  return typeof n === 'number' && Number.isInteger(n) && n >= 0 ? n : null;
}

/** blockID/txID — ровно 32 байта hex в нижнем регистре. */
export function isTronHash(value: unknown): value is string {
  return typeof value === 'string' && HEX64.test(value.toLowerCase());
}

/** ABI-слово с адресом: 12 нулевых байт (24 нуля) + 20 байт. */
function addressWordToHex(word: string): string | null {
  if (word.length !== 64) return null;
  if (!/^0{24}[0-9a-f]{40}$/.test(word)) return null;
  const hex = `41${word.slice(24)}`;
  return TRON_HEX_ADDRESS.test(hex) ? hex : null;
}

function amountWord(word: string): bigint | null {
  if (!HEX64.test(word)) return null;
  return BigInt(`0x${word}`);
}

/**
 * Разбор `data` вызова TriggerSmartContract.
 *
 * Поддерживаются оба способа перевести TRC-20 на депозитный адрес:
 *  - `transfer(address to, uint256 value)`      — `a9059cbb`, ровно 136 hex-символов;
 *  - `transferFrom(address from, address to, uint256 value)` — `23b872dd`, ровно 200.
 *
 * transferFrom нужен потому, что часть бирж и смарт-кошельков выводит средства
 * именно им (msg.sender — spender, а деньги уходят с `from`). В EVM-сетях этот
 * случай уже покрыт: `transferFrom` эмитит тот же самый event `Transfer`,
 * который мы и читаем через getLogs. В Tron событий нет — читаем calldata,
 * поэтому селектор приходится разбирать отдельно.
 *
 * Длина проверяется строго (`===`): «хвост» после параметров означает нештатный
 * вызов, который мы не берёмся интерпретировать.
 */
export function decodeTrc20TransferData(data: string): DecodedTrcTransfer | null {
  if (typeof data !== 'string') return null;
  const hex = stripHexPrefix(data).toLowerCase();
  if (!/^[0-9a-f]*$/.test(hex)) return null;

  if (hex.startsWith(TRC20_TRANSFER_SELECTOR)) {
    if (hex.length !== TRANSFER_DATA_LEN) return null;
    const toHex = addressWordToHex(hex.slice(8, 72));
    const valueRaw = amountWord(hex.slice(72, 136));
    if (toHex === null || valueRaw === null) return null;
    return { method: 'transfer', toHex, fromHex: null, valueRaw };
  }

  if (hex.startsWith(TRC20_TRANSFER_FROM_SELECTOR)) {
    if (hex.length !== TRANSFER_FROM_DATA_LEN) return null;
    const fromHex = addressWordToHex(hex.slice(8, 72));
    const toHex = addressWordToHex(hex.slice(72, 136));
    const valueRaw = amountWord(hex.slice(136, 200));
    if (fromHex === null || toHex === null || valueRaw === null) return null;
    return { method: 'transferFrom', toHex, fromHex, valueRaw };
  }

  return null;
}

/**
 * Извлекает успешные TRC-20 переводы на отслеживаемые контракты из блока TronGrid.
 * `contracts` — карта hex-адрес контракта (41…, нижний регистр) -> конфиг токена.
 *
 * Блок с битым/отсутствующим blockID или номером не разбирается вовсе: без хеша
 * блока отчёт в backend нечем привязать к цепочке.
 */
export function parseTronBlock(block: TronBlock, contracts: Map<string, TokenConfig>): TronTransfer[] {
  const blockNumber = blockNumberOf(block);
  if (blockNumber === null) return [];

  const blockHash = typeof block.blockID === 'string' ? block.blockID.toLowerCase() : '';
  if (!isTronHash(blockHash)) return [];

  const out: TronTransfer[] = [];

  for (const tx of block.transactions ?? []) {
    if (!tx || typeof tx !== 'object') continue;
    // Успех на момент обнаружения; перед `confirmed` результат перепроверяется
    // через wallet/gettransactioninfobyid (SPEC §7.4).
    if (tx.ret?.[0]?.contractRet !== 'SUCCESS') continue;

    const txHash = typeof tx.txID === 'string' ? tx.txID.toLowerCase() : '';
    if (!isTronHash(txHash)) continue;

    const contracts0 = tx.raw_data?.contract;
    if (!Array.isArray(contracts0) || contracts0.length !== 1) continue;
    const contract = contracts0[0];
    if (!contract || contract.type !== 'TriggerSmartContract') continue;

    const value = contract.parameter?.value;
    const contractHex =
      typeof value?.contract_address === 'string' ? stripHexPrefix(value.contract_address).toLowerCase() : '';
    if (!TRON_HEX_ADDRESS.test(contractHex) || !contracts.has(contractHex)) continue;

    const data = value?.data;
    if (typeof data !== 'string' || data === '') continue;
    const decoded = decodeTrc20TransferData(data);
    if (!decoded) continue;

    const ownerHex = typeof value?.owner_address === 'string' ? stripHexPrefix(value.owner_address).toLowerCase() : '';
    // Для transferFrom деньги списываются с параметра `from`, а не с msg.sender.
    const fromHex = decoded.fromHex ?? ownerHex;

    let to: string;
    let from: string;
    try {
      to = tronHexToBase58(decoded.toHex);
      from = TRON_HEX_ADDRESS.test(fromHex) ? tronHexToBase58(fromHex) : '';
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
      ownerHex,
      method: decoded.method,
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
      method: transfer.method,
      owner_address_hex: transfer.ownerHex,
      from_address_hex: transfer.fromHex,
      to_address_hex: transfer.toHex,
      decimals: token.decimals,
    },
  };
}
