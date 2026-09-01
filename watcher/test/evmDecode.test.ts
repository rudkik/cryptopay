import { describe, expect, it } from 'vitest';
import { toBeHex } from 'ethers';
import {
  TRANSFER_TOPIC,
  addressToTopic,
  buildEvmReport,
  parseTransferLog,
  type RawLog,
} from '../src/scanner/evmDecode.js';
import { formatAmount } from '../src/amount.js';
import type { TokenConfig } from '../src/types.js';

const USDT_ETH: TokenConfig = {
  symbol: 'USDT',
  contract_address: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
  decimals: 6,
};
const USDT_BSC: TokenConfig = {
  symbol: 'USDT',
  contract_address: '0x55d398326f99059fF775485246999027B3197955',
  decimals: 18,
};

const FROM = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
const TO = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';

function makeLog(overrides: Partial<RawLog> = {}, valueRaw = 100_500_000n): RawLog {
  return {
    address: USDT_ETH.contract_address,
    topics: [TRANSFER_TOPIC, addressToTopic(FROM), addressToTopic(TO)],
    data: toBeHex(valueRaw, 32),
    blockNumber: 19_000_000,
    blockHash: '0xaaaa000000000000000000000000000000000000000000000000000000000001',
    transactionHash: '0xbbbb000000000000000000000000000000000000000000000000000000000002',
    index: 5,
    ...overrides,
  };
}

describe('EVM Transfer log decoding', () => {
  it('has the canonical Transfer topic', () => {
    expect(TRANSFER_TOPIC).toBe('0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef');
  });

  it('pads an address into a 32-byte topic', () => {
    expect(addressToTopic(TO)).toBe('0x000000000000000000000000f39fd6e51aad88f6f4ce6ab8827279cfffb92266');
    // регистр входа не влияет на topic
    expect(addressToTopic(TO.toLowerCase())).toBe(addressToTopic(TO));
  });

  it('decodes a Transfer log into from/to/value', () => {
    const ev = parseTransferLog(makeLog());
    expect(ev).not.toBeNull();
    expect(ev!.from).toBe(FROM);
    expect(ev!.to).toBe(TO);
    expect(ev!.valueRaw).toBe(100_500_000n);
    expect(ev!.contract).toBe(USDT_ETH.contract_address);
    expect(ev!.blockNumber).toBe(19_000_000);
    expect(ev!.logIndex).toBe(5);
  });

  it('accepts the legacy logIndex field name', () => {
    const ev = parseTransferLog(makeLog({ index: undefined, logIndex: 9 }));
    expect(ev!.logIndex).toBe(9);
  });

  it('ignores non-Transfer, malformed and removed logs', () => {
    const approval = '0x8c5be1e5ebec7d5bd14f71427d1e84f3dd0314c0f7b2291e5b200ac8c7c3b925';
    expect(parseTransferLog(makeLog({ topics: [approval, addressToTopic(FROM), addressToTopic(TO)] }))).toBeNull();
    // ERC-721 Transfer имеет 4 топика и пустой data — но у нас 2-топиковый случай отсекается длиной
    expect(parseTransferLog(makeLog({ topics: [TRANSFER_TOPIC, addressToTopic(FROM)] }))).toBeNull();
    expect(parseTransferLog(makeLog({ removed: true }))).toBeNull();
    expect(parseTransferLog(makeLog({ index: undefined, logIndex: undefined }))).toBeNull();
  });

  it('builds the backend payload with human-readable and raw amounts', () => {
    const ev = parseTransferLog(makeLog())!;
    const report = buildEvmReport('ethereum', ev, USDT_ETH, 3);

    expect(report).toMatchObject({
      network: 'ethereum',
      tx_hash: '0xbbbb000000000000000000000000000000000000000000000000000000000002',
      log_index: 5,
      contract_address: USDT_ETH.contract_address,
      symbol: 'USDT',
      from_address: FROM,
      to_address: TO,
      amount_raw: '100500000',
      amount: '100.5',
      block_number: 19_000_000,
      confirmations: 3,
      status: 'detected',
    });
  });

  it('handles 18-decimal BEP-20 amounts without precision loss', () => {
    const valueRaw = 1_234_567_890_123_456_789n; // 1.234567890123456789 USDT (BSC)
    const ev = parseTransferLog(
      makeLog({ address: USDT_BSC.contract_address }, valueRaw),
    )!;
    const report = buildEvmReport('bsc', ev, USDT_BSC, 1);
    expect(report.amount_raw).toBe('1234567890123456789');
    expect(report.amount).toBe('1.234567890123456789');
  });

  it('formats amounts without trailing zeros', () => {
    expect(formatAmount(100_000_000n, 6)).toBe('100');
    expect(formatAmount('1', 6)).toBe('0.000001');
    expect(formatAmount(0n, 6)).toBe('0');
    expect(formatAmount(10n ** 24n, 18)).toBe('1000000');
  });
});
