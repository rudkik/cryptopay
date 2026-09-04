import { describe, expect, it, vi } from 'vitest';
import { getAddress, zeroPadValue } from 'ethers';
import { EvmScanner } from '../src/scanner/evm.js';
import { WatchAddressSet } from '../src/scanner/addresses.js';
import { TRANSFER_TOPIC, addressToTopic, parseTransferLog } from '../src/scanner/evmDecode.js';
import type { AppConfig } from '../src/config.js';
import type { BackendClient } from '../src/backend/client.js';
import type { StateStore, NetworkState } from '../src/state/store.js';
import type { NetworkConfig, TransactionReport } from '../src/types.js';

const cfg = {
  maxLagBlocks: 50,
  maxConsecutiveErrors: 3,
  evmBatchBlocks: 20,
  reorgDepth: 64,
} as unknown as AppConfig;

const USDT = '0xdAC17F958D2ee523a2206206994597C13D831ec7';
/** Тот же байткод события, но чужой контракт — «фальшивый USDT». */
const FAKE_USDT = '0xdaC17F958d2Ee523a2206206994597c13D831EC8';
const WATCHED = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
const SENDER = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

const netConfig: NetworkConfig = {
  code: 'ethereum',
  chain_id: 1,
  confirmations_required: 12,
  is_enabled: true,
  last_scanned_block: null,
  tokens: [{ symbol: 'USDT', contract_address: USDT, decimals: 6 }],
};

const silentLog = { warn() {}, info() {}, error() {}, debug() {}, child: () => silentLog } as never;

interface ProviderStub {
  getBlockNumber: () => Promise<number>;
  getBlock: (n: number) => Promise<{ hash: string } | null>;
  getLogs: (filter: unknown) => Promise<unknown[]>;
  getTransactionReceipt?: (hash: string) => Promise<unknown>;
}

function build(
  persisted: NetworkState,
  provider: ProviderStub,
  watched: string[] = [WATCHED],
): { scanner: EvmScanner; reported: TransactionReport[]; state: () => NetworkState } {
  let stored = persisted;
  const reported: TransactionReport[] = [];

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

  const addresses = new WatchAddressSet('ethereum');
  addresses.replace(watched.map((address, i) => ({ id: String(i), address })));

  const scanner = new EvmScanner('ethereum', netConfig, 'http://rpc.invalid', cfg, backend, store, addresses, silentLog);
  (scanner as unknown as { provider: ProviderStub }).provider = provider;
  return { scanner, reported, state: () => stored };
}

function transferLog(overrides: Partial<Record<string, unknown>> = {}): Record<string, unknown> {
  return {
    address: USDT,
    topics: [TRANSFER_TOPIC, zeroPadValue(SENDER, 32), zeroPadValue(WATCHED, 32)],
    data: `0x${(1_000_000n).toString(16).padStart(64, '0')}`,
    blockNumber: 100,
    blockHash: `0x${'aa'.repeat(32)}`,
    transactionHash: `0x${'bb'.repeat(32)}`,
    logIndex: 7,
    ...overrides,
  };
}

describe('EVM topic-фильтр', () => {
  it('padded topic одинаков для нижнего регистра и checksum-формы', () => {
    expect(addressToTopic(WATCHED.toLowerCase())).toBe(addressToTopic(WATCHED));
    expect(addressToTopic(WATCHED.toUpperCase().replace('0X', '0x'))).toBe(addressToTopic(WATCHED));
    expect(addressToTopic(WATCHED)).toBe(zeroPadValue(getAddress(WATCHED), 32).toLowerCase());
  });

  it('getLogs получает topic-фильтр в нижнем регистре независимо от формы адреса в БД', async () => {
    const filters: { topics?: unknown[] }[] = [];
    const { scanner } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => 100,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs: async (f) => {
          filters.push(f as { topics?: unknown[] });
          return [];
        },
      },
      // одна и та же учётка, записанная тремя способами
      [WATCHED, WATCHED.toLowerCase(), WATCHED],
    );

    await scanner.tick();

    expect(filters).toHaveLength(1);
    const toTopics = filters[0]!.topics![2] as string[];
    // WatchAddressSet дедуплицирует по нижнему регистру -> один topic
    expect(toTopics).toEqual([addressToTopic(WATCHED)]);
    expect(toTopics[0]).toBe(toTopics[0]!.toLowerCase());
  });
});

describe('EVM: чужой контракт с той же сигнатурой события', () => {
  it('parseTransferLog разбирает лог, но контракт-фильтр его не принимает', async () => {
    const fake = transferLog({ address: FAKE_USDT });
    // Сам по себе лог валиден — отсев обязан произойти по адресу контракта.
    expect(parseTransferLog(fake as never)).not.toBeNull();

    const { scanner, reported } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => 100,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        // Симулируем «нода вернула лишнее»: RPC, который проигнорировал фильтр
        // address, или сознательно вредоносный провайдер.
        getLogs: async () => [fake, transferLog()],
      },
    );

    await scanner.tick();

    expect(reported).toHaveLength(1);
    expect(reported[0]!.contract_address).toBe(getAddress(USDT));
    expect(reported.some((r) => r.contract_address === getAddress(FAKE_USDT))).toBe(false);
  });

  it('transferFrom и перевод из контракта-агрегатора обрабатываются как обычный Transfer', async () => {
    // На уровне логов transferFrom и вызов из роутера неотличимы от transfer:
    // это тот же event Transfer, эмитированный контрактом токена. Проверяем,
    // что оба доезжают до backend с одинаковой семантикой (from — владелец
    // средств, to — наш адрес).
    const router = getAddress('0x1111111254EEB25477B68fb85Ed929f73A960582');
    const viaTransferFrom = transferLog({
      topics: [TRANSFER_TOPIC, zeroPadValue(SENDER, 32), zeroPadValue(WATCHED, 32)],
      transactionHash: `0x${'11'.repeat(32)}`,
      logIndex: 1,
    });
    const viaRouter = transferLog({
      topics: [TRANSFER_TOPIC, zeroPadValue(router, 32), zeroPadValue(WATCHED, 32)],
      transactionHash: `0x${'22'.repeat(32)}`,
      logIndex: 42,
    });

    const { scanner, reported } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => 100,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs: async () => [viaTransferFrom, viaRouter],
      },
    );

    await scanner.tick();

    expect(reported).toHaveLength(2);
    expect(reported.map((r) => r.from_address)).toEqual([getAddress(SENDER), router]);
    expect(new Set(reported.map((r) => r.to_address))).toEqual(new Set([getAddress(WATCHED)]));
    expect(new Set(reported.map((r) => r.amount))).toEqual(new Set(['1']));
  });
});

describe('EVM подтверждение: пустой receipt', () => {
  const detected = transferLog();

  function scannerWithPending(receipts: (unknown | null)[]): ReturnType<typeof build> & { heads: number[] } {
    const heads = [100, 120, 121, 122];
    let i = 0;
    let receiptCall = 0;
    const built = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => heads[Math.min(i++, heads.length - 1)]!,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs: async () => (receiptCall === 0 ? [detected] : []),
        getTransactionReceipt: async () => receipts[Math.min(receiptCall++, receipts.length - 1)] ?? null,
      },
    );
    return { ...built, heads };
  }

  it('REGRESSION: null receipt оставляет транзакцию в pending, а не отменяет платёж', async () => {
    const { scanner, reported } = scannerWithPending([null, null, null]);

    await scanner.tick(); // detected
    await scanner.tick(); // head 120 -> порог достигнут, receipt пуст
    await scanner.tick(); // head 121 -> receipt всё ещё пуст

    expect(reported.map((r) => r.status)).not.toContain('orphaned');
    expect(scanner.pendingCount()).toBe(1);
  });

  it('receipt, появившийся после нескольких пустых ответов, приводит к confirmed', async () => {
    const good = { status: 1, blockHash: `0x${'aa'.repeat(32)}` };
    const { scanner, reported } = scannerWithPending([null, null, good]);

    await scanner.tick(); // detected
    await scanner.tick(); // порог достигнут, receipt пуст -> ждём
    await scanner.tick(); // receipt пуст -> ждём
    await scanner.tick(); // receipt появился -> confirmed

    expect(reported.filter((r) => r.status === 'orphaned')).toHaveLength(0);
    expect(reported.filter((r) => r.status === 'confirmed')).toHaveLength(1);
    expect(scanner.pendingCount()).toBe(0);
  });

  it('ревертнувшаяся транзакция (status 0) уходит в orphaned после повторного вердикта', async () => {
    const reverted = { status: 0, blockHash: `0x${'aa'.repeat(32)}` };
    const { scanner, reported } = scannerWithPending([reverted, reverted, reverted]);

    await scanner.tick();
    await scanner.tick();
    expect(reported.filter((r) => r.status === 'orphaned')).toHaveLength(0);
    await scanner.tick();
    expect(reported.filter((r) => r.status === 'orphaned')).toHaveLength(1);
  });
});

describe('EVM: пустой ответ backend не должен стирать список адресов', () => {
  it('сканер не двигает курсор молча, если следить не за кем', async () => {
    const getLogs = vi.fn(async () => []);
    const { scanner } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => 100,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs,
      },
      [],
    );

    await scanner.tick();
    // Нет адресов — getLogs не зовём (нечего искать), но и ошибки нет.
    expect(getLogs).not.toHaveBeenCalled();
    expect(scanner.lastError()).toBeNull();
  });
});

describe('EVM: устойчивость цикла сканирования', () => {
  it('REGRESSION: getLogs с таймаутом не двигает курсор — диапазон будет перечитан', async () => {
    let calls = 0;
    const { scanner, state } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => 110,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs: async () => {
          calls += 1;
          throw new Error('failed to fetch: network timeout');
        },
      },
    );

    await scanner.tick();

    expect(calls).toBe(1);
    expect(scanner.lastScannedBlock()).toBe(99); // ни одного пропущенного блока
    expect(state().lastScannedBlock).toBe(99);
    expect(scanner.lastError()).not.toBeNull();
    expect(scanner.healthy()).toBe(true); // одна ошибка — ещё не «нездоров»
  });

  it('ошибка «диапазон слишком большой» ужимает батч, а не пропускает блоки', async () => {
    const ranges: [number, number][] = [];
    let fail = true;
    const { scanner } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => 200,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs: async (f) => {
          const filter = f as { fromBlock: number; toBlock: number };
          ranges.push([filter.fromBlock, filter.toBlock]);
          if (fail) {
            fail = false;
            throw new Error('query returned more than 10000 results');
          }
          return [];
        },
      },
    );

    await scanner.tick();
    expect(scanner.lastScannedBlock()).toBe(99); // первый заход не сдвинул курсор
    await scanner.tick();

    expect(ranges[0]).toEqual([100, 119]);
    expect(ranges[1]![0]).toBe(100); // перечитываем с того же места
    expect(ranges[1]![1]).toBeLessThan(119); // но меньшим окном
    expect(scanner.lastScannedBlock()).toBe(ranges[1]![1]);
  });

  it('REGRESSION: 429 от getLogs не «ужимает батч», а поднимается как ошибка', async () => {
    // «too many requests» / «rate limit exceeded» подходят под RANGE_ERROR по
    // словам «too many» и «limit». Раньше это уводило 429 в ветку сжатия батча:
    // окно съезжало до 1 блока, а lastError затирался state.ok() — сеть
    // выглядела здоровой, backoff не включался, RPC продолжал получать шквал.
    const ranges: [number, number][] = [];
    const { scanner } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => 110,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs: async (f) => {
          const filter = f as { fromBlock: number; toBlock: number };
          ranges.push([filter.fromBlock, filter.toBlock]);
          throw new Error('rate limit exceeded: too many requests (HTTP 429)');
        },
      },
    );

    await scanner.tick();
    await scanner.tick();

    expect(scanner.lastError()).not.toBeNull();
    expect(scanner.lastError()).toContain('429');
    // Окно не сжималось: обе попытки — полный батч с той же стартовой высоты.
    expect(ranges).toEqual([
      [100, 110],
      [100, 110],
    ]);
  });

  it('три ошибки подряд -> healthy=false (heartbeat увидит)', async () => {
    const { scanner } = build(
      { lastScannedBlock: 99, recentBlocks: [], pending: [] },
      {
        getBlockNumber: async () => {
          throw new Error('HTTP 429 too many requests');
        },
        getBlock: async () => null,
        getLogs: async () => [],
      },
    );

    await scanner.tick();
    expect(scanner.healthy()).toBe(true);
    await scanner.tick();
    expect(scanner.healthy()).toBe(true);
    await scanner.tick();
    expect(scanner.healthy()).toBe(false);
    expect(scanner.lastError()).toContain('429');
  });

  it('лаг больше MAX_LAG_BLOCKS -> healthy=false даже без ошибок RPC', async () => {
    const { scanner } = build(
      { lastScannedBlock: 1000, recentBlocks: [], pending: [] },
      {
        // head ушёл на 100_000 блоков вперёд: догоняем, но здоровыми не считаемся
        getBlockNumber: async () => 101_000,
        getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
        getLogs: async () => [],
      },
    );

    await scanner.tick();

    expect(scanner.lastError()).toBeNull();
    expect(scanner.health().lag!).toBeGreaterThan(cfg.maxLagBlocks);
    expect(scanner.healthy()).toBe(false);
  });

  it('рестарт продолжает с lastScanned + 1 и не перечитывает уже пройденное', async () => {
    const ranges: [number, number][] = [];
    const provider = {
      getBlockNumber: async () => 200,
      getBlock: async () => ({ hash: `0x${'cc'.repeat(32)}` }),
      getLogs: async (f: unknown) => {
        const filter = f as { fromBlock: number; toBlock: number };
        ranges.push([filter.fromBlock, filter.toBlock]);
        return [];
      },
    };

    const first = build({ lastScannedBlock: 99, recentBlocks: [], pending: [] }, provider);
    await first.scanner.tick();
    const persisted = first.state();
    expect(persisted.lastScannedBlock).toBe(119);

    // «Рестарт»: новый сканер поднимается из того же файла состояния.
    const second = build(persisted, provider);
    await second.scanner.tick();

    expect(ranges).toEqual([
      [100, 119],
      [120, 139],
    ]);
  });
});
