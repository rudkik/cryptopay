import { describe, expect, it } from 'vitest';
import { EvmScanner } from '../src/scanner/evm.js';
import { WatchAddressSet } from '../src/scanner/addresses.js';
import type { AppConfig } from '../src/config.js';
import type { BackendClient } from '../src/backend/client.js';
import type { StateStore, NetworkState } from '../src/state/store.js';
import type { NetworkConfig } from '../src/types.js';

const cfg = {
  maxLagBlocks: 50,
  maxConsecutiveErrors: 3,
  evmBatchBlocks: 20,
  reorgDepth: 64,
} as unknown as AppConfig;

const netConfig: NetworkConfig = {
  code: 'ethereum',
  chain_id: 1,
  confirmations_required: 12,
  is_enabled: true,
  last_scanned_block: null,
  tokens: [{ symbol: 'USDT', contract_address: '0xdAC17F958D2ee523a2206206994597C13D831ec7', decimals: 6 }],
};

const silentLog = { warn() {}, info() {}, error() {}, debug() {}, child: () => silentLog } as never;

interface ProviderStub {
  getBlockNumber: () => Promise<number>;
  getBlock: (n: number) => Promise<{ hash: string } | null>;
  getLogs: () => Promise<unknown[]>;
}

function build(persisted: NetworkState, provider: ProviderStub) {
  let stored = persisted;
  const store = {
    get: () => stored,
    set: (_n: string, next: NetworkState) => {
      stored = next;
    },
    touch() {},
  } as unknown as StateStore;

  const backend = { postTransaction: async () => {}, postTransactionBatch: async () => {} } as unknown as BackendClient;

  const addresses = new WatchAddressSet('ethereum');
  addresses.replace([]); // loaded, но пустой: getLogs не вызывается

  const scanner = new EvmScanner(
    'ethereum',
    netConfig,
    'http://rpc.invalid',
    cfg,
    backend,
    store,
    addresses,
    silentLog,
  );
  (scanner as unknown as { provider: ProviderStub }).provider = provider;
  return { scanner, state: () => stored };
}

describe('EvmScanner reorg checkpoint handling', () => {
  const HEAD = 21_000_000;

  it('does not re-query a checkpoint deeper than reorgDepth (public RPCs 403 on archive)', async () => {
    const asked: number[] = [];
    const { scanner } = build(
      // Чекпоинт на 200k блоков позади: реорг такой глубины невозможен.
      { lastScannedBlock: HEAD - 200_000, recentBlocks: [{ number: HEAD - 200_000, hash: `0x${'11'.repeat(32)}` }], pending: [] },
      {
        getBlockNumber: async () => HEAD,
        getBlock: async (n: number) => {
          asked.push(n);
          throw new Error('missing trie node / archive request');
        },
        getLogs: async () => [],
      },
    );

    await scanner.tick();

    // Древний чекпоинт вообще не запрашивается (единственный getBlock —
    // запись хеша только что просканированного блока в конце scanForward).
    expect(asked).not.toContain(HEAD - 200_000);
    // Тик не упал и курсор поехал вперёд.
    expect(scanner.lastError()).toBeNull();
    expect(scanner.lastScannedBlock()).toBeGreaterThan(HEAD - 200_000);
  });

  it('survives a failing block lookup inside the reorg window without wedging the tick', async () => {
    const { scanner } = build(
      { lastScannedBlock: HEAD - 10, recentBlocks: [{ number: HEAD - 10, hash: `0x${'22'.repeat(32)}` }], pending: [] },
      {
        getBlockNumber: async () => HEAD,
        getBlock: async () => {
          throw new Error('rpc unavailable');
        },
        getLogs: async () => [],
      },
    );

    await scanner.tick();

    expect(scanner.lastError()).toBeNull();
    expect(scanner.lastScannedBlock()).toBe(HEAD - 9 + cfg.evmBatchBlocks - 1 > HEAD ? HEAD : HEAD - 9 + cfg.evmBatchBlocks - 1);
  });

  it('rewinds and revalidates when the checkpoint hash no longer matches', async () => {
    const { scanner } = build(
      { lastScannedBlock: HEAD - 5, recentBlocks: [{ number: HEAD - 5, hash: `0x${'33'.repeat(32)}` }], pending: [] },
      {
        getBlockNumber: async () => HEAD,
        getBlock: async () => ({ hash: `0x${'44'.repeat(32)}` }),
        getLogs: async () => [],
      },
    );

    await scanner.tick();

    // rewindTo = (HEAD-5) - 64 + 1; курсор откатился далеко назад.
    expect(scanner.lastScannedBlock()).toBeLessThan(HEAD - 5);
  });
});
