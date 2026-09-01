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

function topicToAddress(topic: string): string {
  return getAddress(`0x${topic.slice(-40)}`);
}

/**
 * Разбор лога Transfer. Возвращает null, если лог не является валидным
 * 3-топиковым Transfer (например, это Approval или лог удалён реоргом).
 */
export function parseTransferLog(log: RawLog): TransferEvent | null {
  if (log.removed) return null;
  if (!Array.isArray(log.topics) && !(log.topics as readonly string[])?.length) return null;
  const topics = log.topics as readonly string[];
  if (topics.length < 3) return null;
  const topic0 = topics[0];
  if (!topic0 || topic0.toLowerCase() !== TRANSFER_TOPIC) return null;

  const fromTopic = topics[1];
  const toTopic = topics[2];
  if (!fromTopic || !toTopic) return null;

  const data = log.data && log.data !== '0x' ? log.data : '0x0';
  let valueRaw: bigint;
  try {
    valueRaw = BigInt(data);
  } catch {
    return null;
  }

  const logIndex = log.index ?? log.logIndex;
  if (logIndex === undefined) return null;

  return {
    contract: getAddress(log.address),
    from: topicToAddress(fromTopic),
    to: topicToAddress(toTopic),
    valueRaw,
    blockNumber: Number(log.blockNumber),
    blockHash: log.blockHash,
    txHash: log.transactionHash,
    logIndex: Number(logIndex),
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
