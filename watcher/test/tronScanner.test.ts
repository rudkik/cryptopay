import { describe, expect, it } from 'vitest';
import { TronScanner } from '../src/scanner/tron.js';
import { WatchAddressSet } from '../src/scanner/addresses.js';
import type { TronBlock } from '../src/scanner/tronDecode.js';
import type { TronClient, TronTransactionInfo } from '../src/scanner/tronClient.js';
import type { AppConfig } from '../src/config.js';
import type { BackendClient } from '../src/backend/client.js';
import type { StateStore, NetworkState } from '../src/state/store.js';
import type { NetworkConfig, TransactionReport } from '../src/types.js';

const USDT_HEX = '41a614f803b6fd780986a42c78ec9c7f77e6ded13c';
const TO_BASE58 = 'TWer2Ygk5TEheHp3TPuYeqxmB6SsGZmaL6';
const TO_HEX_BODY = 'e2e1a54926527fbb4e4420de4c6bab82beaee24d';
const OWNER_HEX = '41977c20977f412c2a1aa4ef3d49fe8f5f9a1f5b09';
const TX_ID = 'c1b2a3947f5e6d8c9b0a1f2e3d4c5b6a7988776655443322110099aabbccddee';

const netConfig = (confirmations = 19): NetworkConfig => ({
  code: 'tron',
  chain_id: null,
  confirmations_required: confirmations,
  is_enabled: true,
  last_scanned_block: null,
  tokens: [{ symbol: 'USDT', contract_address: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', decimals: 6 }],
});

const cfg = { maxLagBlocks: 50, maxConsecutiveErrors: 3 } as unknown as AppConfig;
const silentLog = { warn() {}, info() {}, error() {}, debug() {} } as never;

function block(number: number, withTransfer = false): TronBlock {
  const data = `a9059cbb${'0'.repeat(24)}${TO_HEX_BODY}${(5_000_000n).toString(16).padStart(64, '0')}`;
  return {
    blockID: number.toString(16).padStart(64, '0'),
    block_header: { raw_data: { number, timestamp: 1_735_000_000_000 } },
    transactions: withTransfer
      ? [
          {
            ret: [{ contractRet: 'SUCCESS' }],
            txID: TX_ID,
            raw_data: {
              contract: [
                {
                  type: 'TriggerSmartContract',
                  parameter: { value: { data, owner_address: OWNER_HEX, contract_address: USDT_HEX } },
                },
              ],
            },
          },
        ]
      : [],
  };
}

interface Harness {
  scanner: TronScanner;
  reported: TransactionReport[];
  state: () => NetworkState;
}

function harness(opts: {
  head: number;
  solidityHead?: number;
  blocks: TronBlock[];
  info?: TronTransactionInfo | null;
  confirmations?: number;
  lastScanned?: number | null;
}): Harness {
  const reported: TransactionReport[] = [];
  let stored: NetworkState = { lastScannedBlock: opts.lastScanned ?? null, recentBlocks: [], pending: [] };

  const store = {
    get: () => stored,
    set: (_n: string, next: NetworkState) => {
      stored = next;
    },
    touch() {},
  } as unknown as StateStore;

  const backend = {
    postTransaction: async (tx: TransactionReport) => {
      reported.push(tx);
    },
    postTransactionBatch: async (txs: TransactionReport[]) => {
      reported.push(...txs);
    },
  } as unknown as BackendClient;

  const client = {
    getNowBlock: async () => block(opts.head),
    getSolidityNowBlock: async () => block(opts.solidityHead ?? opts.head),
    getBlockByLimit: async () => opts.blocks,
    getTransactionInfoById: async () => opts.info ?? null,
  } as unknown as TronClient;

  const addresses = new WatchAddressSet('tron');
  addresses.replace([{ id: 'a', address: TO_BASE58 }]);

  const scanner = new TronScanner(
    netConfig(opts.confirmations ?? 19),
    client,
    cfg,
    backend,
    store,
    addresses,
    silentLog,
  );

  return { scanner, reported, state: () => stored };
}

describe('TronScanner block cursor', () => {
  it('advances only over blocks the node actually returned', async () => {
    // Запрошено 10 блоков (101..110), TronGrid отдал непрерывный префикс из двух.
    const h = harness({ head: 200, blocks: [block(101), block(102)], lastScanned: 100 });
    await h.scanner.tick();
    expect(h.scanner.lastScannedBlock()).toBe(102);
    expect(h.state().lastScannedBlock).toBe(102);
  });

  it('stops the batch before a block that does not match the expected height', async () => {
    const h = harness({ head: 200, blocks: [block(101), block(999), block(103)], lastScanned: 100 });
    await h.scanner.tick();
    expect(h.scanner.lastScannedBlock()).toBe(101);
  });

  it('stops the batch before a block with a malformed blockID', async () => {
    const bad = block(102);
    bad.blockID = 'nope';
    const h = harness({ head: 200, blocks: [block(101), bad], lastScanned: 100 });
    await h.scanner.tick();
    expect(h.scanner.lastScannedBlock()).toBe(101);
  });

  it('leaves the cursor untouched when nothing came back at all', async () => {
    const h = harness({ head: 200, blocks: [], lastScanned: 100 });
    await h.scanner.tick();
    expect(h.scanner.lastScannedBlock()).toBe(100);
  });
});

describe('TronScanner confirmation gate', () => {
  const detectedIn = (n: number) => [block(n, true)];

  it('confirms only on a SUCCESS receipt for the same txID', async () => {
    const h = harness({
      head: 101,
      blocks: detectedIn(101),
      lastScanned: 100,
      confirmations: 1,
      info: { id: TX_ID, blockNumber: 101, receipt: { result: 'SUCCESS' } },
    });
    await h.scanner.tick();
    expect(h.reported.map((t) => t.status)).toEqual(['detected', 'confirmed']);
    expect(h.reported[0]?.amount).toBe('5');
    expect(h.reported[0]?.to_address).toBe(TO_BASE58);
  });

  it('orphans a transaction whose top-level result is FAILED', async () => {
    const h = harness({
      head: 101,
      blocks: detectedIn(101),
      lastScanned: 100,
      confirmations: 1,
      info: { id: TX_ID, blockNumber: 101, result: 'FAILED', receipt: { result: 'SUCCESS' } },
    });
    await h.scanner.tick();
    expect(h.reported.map((t) => t.status)).toEqual(['detected', 'orphaned']);
  });

  it('orphans a transaction whose receipt reverted', async () => {
    const h = harness({
      head: 101,
      blocks: detectedIn(101),
      lastScanned: 100,
      confirmations: 1,
      info: { id: TX_ID, blockNumber: 101, receipt: { result: 'REVERT' } },
    });
    await h.scanner.tick();
    expect(h.reported.map((t) => t.status)).toEqual(['detected', 'orphaned']);
  });

  it('never confirms on a receipt belonging to another transaction', async () => {
    const h = harness({
      head: 101,
      blocks: detectedIn(101),
      lastScanned: 100,
      confirmations: 1,
      info: { id: 'f'.repeat(64), blockNumber: 101, receipt: { result: 'SUCCESS' } },
    });
    await h.scanner.tick();
    expect(h.reported.map((t) => t.status)).toEqual(['detected']);
    expect(h.scanner.pendingCount()).toBe(1);
  });

  it('waits instead of confirming while the block is above the solidity head', async () => {
    const h = harness({
      head: 101,
      solidityHead: 99,
      blocks: detectedIn(101),
      lastScanned: 100,
      confirmations: 1,
      info: { id: TX_ID, blockNumber: 101, receipt: { result: 'SUCCESS' } },
    });
    await h.scanner.tick();
    expect(h.reported.map((t) => t.status)).toEqual(['detected']);
    expect(h.scanner.pendingCount()).toBe(1);
  });

  it('ignores a zero-value transfer', async () => {
    const zero = block(101, true);
    const value = zero.transactions![0]!.raw_data!.contract![0]!.parameter!.value!;
    value.data = `a9059cbb${'0'.repeat(24)}${TO_HEX_BODY}${'0'.repeat(64)}`;
    const h = harness({ head: 101, blocks: [zero], lastScanned: 100, confirmations: 1 });
    await h.scanner.tick();
    expect(h.reported).toEqual([]);
  });

  it('ignores a transfer to an address that is not being watched', async () => {
    const other = block(101, true);
    const value = other.transactions![0]!.raw_data!.contract![0]!.parameter!.value!;
    value.data = `a9059cbb${'0'.repeat(24)}${'1'.repeat(40)}${(5_000_000n).toString(16).padStart(64, '0')}`;
    const h = harness({ head: 101, blocks: [other], lastScanned: 100, confirmations: 1 });
    await h.scanner.tick();
    expect(h.reported).toEqual([]);
  });
});
