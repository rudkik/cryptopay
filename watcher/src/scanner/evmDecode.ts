import { getAddress, id, zeroPadValue } from 'ethers';
import { formatAmount } from '../amount.js';
import type { NetworkCode, TokenConfig, TransactionReport } from '../types.js';

/** topic0 события ERC-20 Transfer(address,address,uint256). */
export const TRANSFER_TOPIC = id('Transfer(address,address,uint256)');

export interface RawLog {
  address: string;
  topics: readonly string[];
  data: string;
  blockNumber: number;
  blockHash: string;
  transactionHash: string;
  /** ethers v6 отдаёт `index`; сохраняем совместимость с `logIndex`. */
  index?: number;
  logIndex?: number;
  removed?: boolean;
}

export interface TransferEvent {
  contract: string;
  from: string;
  to: string;
  valueRaw: bigint;
  blockNumber: number;
  blockHash: string;
  txHash: string;
  logIndex: number;
}

/** address -> 32-байтный topic для фильтра getLogs. */
export function addressToTopic(address: string): string {
  return zeroPadValue(getAddress(address), 32).toLowerCase();
}

/** topic (32 байта) -> адрес. null, если topic не является каноничным адресом. */
function topicToAddress(topic: unknown): string | null {
  if (typeof topic !== 'string') return null;
  // Каноничный адресный topic: 12 нулевых байт + 20 байт адреса.
  if (!/^0x0{24}[0-9a-fA-F]{40}$/.test(topic)) return null;
  try {
    return getAddress(`0x${topic.slice(-40)}`);
  } catch {
    return null;
  }
}

/** 32-байтное слово uint256 в data. */
const UINT256_DATA = /^0x[0-9a-fA-F]{64}$/;
const HASH32 = /^0x[0-9a-fA-F]{64}$/;

function isHash32(value: unknown): value is string {
  return typeof value === 'string' && HASH32.test(value);
}

/**
 * Разбор лога Transfer(address,address,uint256).
 *
 * Возвращает null для всего, что не является каноничным ERC-20 Transfer:
 *  - `removed: true` (лог вычищен реоргом);
 *  - topics != ровно 3 (ERC-721 Transfer индексирует tokenId и даёт 4 topic'а —
 *    такой лог НЕ является переводом токена и должен игнорироваться);
 *  - topic0 != Transfer;
 *  - неканоничные адресные topic'и (мусор в старших 12 байтах);
 *  - `data` не ровно 32 байта (битый/нестандартный лог — пропускаем безопасно,
 *    иначе BigInt() съел бы произвольно длинное число);
 *  - отсутствующие/битые хеши блока и транзакции.
 *
 * ВАЖНО: адрес контракта здесь только нормализуется; сверка со списком
 * сконфигурированных контрактов — на вызывающей стороне (регистронезависимо).
 */
export function parseTransferLog(log: RawLog): TransferEvent | null {
  if (!log || typeof log !== 'object') return null;
  if (log.removed === true) return null;

  const topics = log.topics;
  if (!Array.isArray(topics) || topics.length !== 3) return null;

  const topic0 = topics[0];
  if (typeof topic0 !== 'string' || topic0.toLowerCase() !== TRANSFER_TOPIC) return null;

  const from = topicToAddress(topics[1]);
  const to = topicToAddress(topics[2]);
  if (from === null || to === null) return null;

  if (typeof log.data !== 'string' || !UINT256_DATA.test(log.data)) return null;
  let valueRaw: bigint;
  try {
    valueRaw = BigInt(log.data);
  } catch {
    return null;
  }

  if (!isHash32(log.blockHash) || !isHash32(log.transactionHash)) return null;

  const blockNumber = Number(log.blockNumber);
  if (!Number.isInteger(blockNumber) || blockNumber < 0) return null;

  const rawLogIndex = log.index ?? log.logIndex;
  const logIndex = Number(rawLogIndex);
  if (rawLogIndex === undefined || !Number.isInteger(logIndex) || logIndex < 0) return null;

  let contract: string;
  try {
    contract = getAddress(log.address);
  } catch {
    return null;
  }

  return {
    contract,
    from,
    to,
    valueRaw,
    blockNumber,
    blockHash: log.blockHash,
    txHash: log.transactionHash,
    logIndex,
  };
}

/** Сборка тела POST /api/internal/transactions из декодированного Transfer. */
export function buildEvmReport(
  network: NetworkCode,
  ev: TransferEvent,
  token: TokenConfig,
  confirmations: number,
): TransactionReport {
  return {
    network,
    tx_hash: ev.txHash,
    log_index: ev.logIndex,
    contract_address: ev.contract,
    symbol: token.symbol,
    from_address: ev.from,
    to_address: ev.to,
    amount_raw: ev.valueRaw.toString(),
    amount: formatAmount(ev.valueRaw, token.decimals),
    block_number: ev.blockNumber,
    block_hash: ev.blockHash,
    confirmations,
    status: 'detected',
    raw: {
      contract: ev.contract,
      log_index: ev.logIndex,
      block_hash: ev.blockHash,
      decimals: token.decimals,
    },
  };
}
