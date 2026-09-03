import { describe, expect, it, beforeEach } from 'vitest';
import { parseTransferLog, type RawLog } from '../src/scanner/evmDecode.js';
import { WatchAddressSet } from '../src/scanner/addresses.js';
import { MAX_RESCAN_DEPTH_BLOCKS, RescanError, ScannerManager } from '../src/scanner/manager.js';
import { clearSecrets, maskUrl, redactSecrets, registerSecret, secretFingerprint } from '../src/redact.js';
import { isPlaceholderToken } from '../src/config.js';
import type { Scanner } from '../src/scanner/base.js';
import type { NetworkCode } from '../src/types.js';

const TRANSFER_TOPIC0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
const topicFor = (addr: string): string => `0x${'0'.repeat(24)}${addr.slice(2).toLowerCase()}`;
const USDT = '0xdAC17F958D2ee523a2206206994597C13D831ec7';
const ALICE = '0x1111111111111111111111111111111111111111';
const BOB = '0x2222222222222222222222222222222222222222';

function log(overrides: Partial<RawLog> = {}): RawLog {
  return {
    address: USDT,
    topics: [TRANSFER_TOPIC0, topicFor(ALICE), topicFor(BOB)],
    data: `0x${(1_000_000n).toString(16).padStart(64, '0')}`,
    blockNumber: 21_000_000,
    blockHash: `0x${'ab'.repeat(32)}`,
    transactionHash: `0x${'cd'.repeat(32)}`,
    index: 4,
    ...overrides,
  } as RawLog;
}

describe('EVM Transfer log validation', () => {
  it('decodes a canonical transfer', () => {
    const ev = parseTransferLog(log());
    expect(ev?.valueRaw).toBe(1_000_000n);
    expect(ev?.to).toBe(BOB);
    expect(ev?.logIndex).toBe(4);
  });

  it('ignores logs removed by a reorg', () => {
    expect(parseTransferLog(log({ removed: true }))).toBeNull();
  });

  it('skips data that is not exactly one uint256 word', () => {
    expect(parseTransferLog(log({ data: '0x' }))).toBeNull();
    expect(parseTransferLog(log({ data: '0x01' }))).toBeNull();
    // «Хвост» после value: BigInt() проглотил бы его и вернул астрономическую сумму.
    expect(parseTransferLog(log({ data: `0x${'0'.repeat(64)}ff` }))).toBeNull();
    expect(parseTransferLog(log({ data: `0x${'z'.repeat(64)}` }))).toBeNull();
    expect(parseTransferLog(log({ data: undefined as unknown as string }))).toBeNull();
  });

  it('skips ERC-721 Transfer (4 topics, indexed tokenId)', () => {
    const erc721 = log({
      topics: [TRANSFER_TOPIC0, topicFor(ALICE), topicFor(BOB), `0x${'0'.repeat(63)}1`],
      data: '0x',
    });
    expect(parseTransferLog(erc721)).toBeNull();
  });

  it('skips address topics with dirt in the top 12 bytes', () => {
    const dirty = `0x${'1'.repeat(24)}${BOB.slice(2)}`;
    expect(parseTransferLog(log({ topics: [TRANSFER_TOPIC0, topicFor(ALICE), dirty] }))).toBeNull();
  });

  it('skips logs with a malformed block/tx hash or index', () => {
    expect(parseTransferLog(log({ blockHash: '0xdead' }))).toBeNull();
    expect(parseTransferLog(log({ transactionHash: 'nope' }))).toBeNull();
    expect(parseTransferLog(log({ index: undefined, logIndex: undefined }))).toBeNull();
    expect(parseTransferLog(log({ blockNumber: Number.NaN }))).toBeNull();
  });

  it('skips non-Transfer topic0 and non-address contract', () => {
    expect(parseTransferLog(log({ topics: [`0x${'1'.repeat(64)}`, topicFor(ALICE), topicFor(BOB)] }))).toBeNull();
    expect(parseTransferLog(log({ address: 'not-an-address' }))).toBeNull();
  });

  it('normalises the contract address so callers can compare case-insensitively', () => {
    expect(parseTransferLog(log({ address: USDT.toLowerCase() }))?.contract).toBe(USDT);
  });
});

describe('WatchAddressSet validation', () => {
  it('drops malformed EVM addresses instead of poisoning getLogs', () => {
    const set = new WatchAddressSet('ethereum');
    set.replace([
      { id: '1', address: '0xdAC17F958D2ee523a2206206994597C13D831ec7' },
      { id: '2', address: 'not-an-address' },
      { id: '3', address: '0x123' },
      { id: '4', address: 'TWer2Ygk5TEheHp3TPuYeqxmB6SsGZmaL6' },
    ]);
    expect(set.size).toBe(1);
    expect(set.rejectedCount).toBe(3);
    // Регистр адреса не важен ни при загрузке, ни при проверке.
    expect(set.has('0xdac17f958d2ee523a2206206994597c13d831ec7')).toBe(true);
    expect(set.has('0xDAC17F958D2EE523A2206206994597C13D831EC7')).toBe(true);
  });

  it('drops tron addresses that fail the base58check', () => {
    const set = new WatchAddressSet('tron');
    set.replace([
      { id: '1', address: 'TWer2Ygk5TEheHp3TPuYeqxmB6SsGZmaL6' },
      { id: '2', address: 'TWer2Ygk5TEheHp3TPuYeqxmB6SsGZmaL7' },
    ]);
    expect(set.size).toBe(1);
    expect(set.rejectedCount).toBe(1);
  });
});

describe('secret redaction', () => {
  beforeEach(() => clearSecrets());

  it('masks the path and query of an RPC url (that is where provider keys live)', () => {
    expect(maskUrl('https://mainnet.infura.io/v3/0123456789abcdef')).toBe('https://mainnet.infura.io/***');
    expect(maskUrl('https://eth.example.com/?apikey=topsecret')).toBe('https://eth.example.com/***');
    expect(maskUrl('https://user:pass@rpc.example.com/x')).toBe('https://rpc.example.com/***');
    expect(maskUrl('https://bsc-rpc.publicnode.com')).toBe('https://bsc-rpc.publicnode.com');
    expect(maskUrl('garbage')).toBe('[redacted-url]');
  });

  it('masks every url inside an ethers error string', () => {
    const raw =
      'server response 403 Forbidden (info={ "requestUrl": "https://eth-mainnet.g.alchemy.com/v2/SECRETKEY" })';
    const out = redactSecrets(raw);
    expect(out).not.toContain('SECRETKEY');
    expect(out).toContain('https://eth-mainnet.g.alchemy.com/***');
  });

  it('masks registered secrets by value', () => {
    registerSecret('super-secret-api-key');
    expect(redactSecrets('TRON-PRO-API-KEY super-secret-api-key rejected')).toBe(
      'TRON-PRO-API-KEY [redacted] rejected',
    );
  });

  it('never registers a value short enough to appear in ordinary text', () => {
    registerSecret('abc');
    expect(redactSecrets('abc')).toBe('abc');
  });

  it('fingerprints a secret without revealing it', () => {
    expect(secretFingerprint('0123456789abcdef')).toBe('***cdef (len=16)');
    expect(secretFingerprint('')).toBe('(empty)');
  });
});

describe('placeholder token detection', () => {
  it('treats empty and change-me* tokens as unusable', () => {
    expect(isPlaceholderToken('')).toBe(true);
    expect(isPlaceholderToken('   ')).toBe(true);
    expect(isPlaceholderToken('change-me-internal-token')).toBe(true);
    expect(isPlaceholderToken('change-me-internal-token-please')).toBe(true);
    expect(isPlaceholderToken('CHANGE-ME-whatever')).toBe(true);
    expect(isPlaceholderToken('e3b0c44298fc1c149afbf4c8996fb924')).toBe(false);
  });
});

describe('/rescan bounds', () => {
  function managerWithHead(head: number | null): { manager: ScannerManager; requested: number[] } {
    const requested: number[] = [];
    const scanner = {
      network: 'ethereum' as NetworkCode,
      headBlock: () => head,
      requestRescan: (n: number) => requested.push(n),
    } as unknown as Scanner;

    const log = { warn() {}, info() {}, error() {}, debug() {} } as never;
    const manager = new ScannerManager({} as never, {} as never, {} as never, log);
    (manager as unknown as { runtimes: Map<string, unknown> }).runtimes.set('ethereum', {
      scanner,
      addresses: null,
      config: null,
      loopStopped: Promise.resolve(),
    });
    return { manager, requested };
  }

  it('rejects a network with no running scanner with 409', () => {
    const { manager } = managerWithHead(100);
    expect(() => manager.rescan('tron', 1)).toThrowError(RescanError);
    try {
      manager.rescan('tron', 1);
    } catch (err) {
      expect((err as RescanError).status).toBe(409);
    }
  });

  it('accepts a rescan inside the safe depth', () => {
    const { manager, requested } = managerWithHead(1_000_000);
    manager.rescan('ethereum', 1_000_000 - MAX_RESCAN_DEPTH_BLOCKS);
    expect(requested).toEqual([1_000_000 - MAX_RESCAN_DEPTH_BLOCKS]);
  });

  it('refuses an absurdly deep rescan unless forced', () => {
    const { manager, requested } = managerWithHead(1_000_000);
    try {
      manager.rescan('ethereum', 1);
      throw new Error('should have thrown');
    } catch (err) {
      expect(err).toBeInstanceOf(RescanError);
      expect((err as RescanError).status).toBe(422);
    }
    expect(requested).toEqual([]);

    manager.rescan('ethereum', 1, true);
    expect(requested).toEqual([1]);
  });

  it('refuses a from_block ahead of head even with force', () => {
    const { manager } = managerWithHead(500);
    expect(() => manager.rescan('ethereum', 900, true)).toThrowError(RescanError);
  });
});
