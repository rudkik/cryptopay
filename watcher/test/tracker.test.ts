import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConfirmationTracker, txKey, type PendingTx, type VerifyResult } from '../src/scanner/tracker.js';
import type { TransactionReport } from '../src/types.js';

const REQUIRED = 12;

function tx(overrides: Partial<TransactionReport> = {}): PendingTx {
  return {
    network: 'ethereum',
    tx_hash: '0xabc0000000000000000000000000000000000000000000000000000000000001',
    log_index: 0,
    contract_address: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
    symbol: 'USDT',
    from_address: '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
    to_address: '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
    amount_raw: '100000000',
    amount: '100',
    block_number: 100,
    block_hash: '0xblock100',
    confirmations: 1,
    status: 'detected',
    raw: {},
    ...overrides,
  };
}

interface Harness {
  tracker: ConfirmationTracker;
  reports: TransactionReport[][];
  verify: ReturnType<typeof vi.fn>;
}

function harness(verifyResult: VerifyResult | (() => VerifyResult | Promise<VerifyResult>) = 'confirmed'): Harness {
  const reports: TransactionReport[][] = [];
  const verify = vi.fn(async () => (typeof verifyResult === 'function' ? verifyResult() : verifyResult));
  const tracker = new ConfirmationTracker({
    network: 'ethereum',
    confirmationsRequired: REQUIRED,
    verify,
    report: async (txs) => {
      reports.push(txs);
    },
  });
  return { tracker, reports, verify };
}

describe('ConfirmationTracker state machine', () => {
  let h: Harness;
  beforeEach(() => {
    h = harness();
  });

  it('reports a transaction as new only once', () => {
    expect(h.tracker.add(tx())).toBe(true);
    expect(h.tracker.add(tx())).toBe(false);
    expect(h.tracker.size).toBe(1);
  });

  it('treats the same hash with a different log_index as a separate transfer', () => {
    h.tracker.add(tx());
    expect(h.tracker.add(tx({ log_index: 1 }))).toBe(true);
    expect(h.tracker.size).toBe(2);
    expect(txKey({ tx_hash: '0xAB', log_index: 3 })).toBe('0xab:3');
  });

  it('re-arms a transaction that reappears in a different block (reorg re-detection)', () => {
    h.tracker.add(tx({ block_number: 100, block_hash: '0xblock100' }));
    expect(h.tracker.add(tx({ block_number: 101, block_hash: '0xblock101' }))).toBe(true);
    expect(h.tracker.size).toBe(1);
    expect(h.tracker.list()[0]!.block_number).toBe(101);
  });

  it('caps confirmations at the required threshold', () => {
    expect(h.tracker.confirmationsFor(100, 100)).toBe(1);
    expect(h.tracker.confirmationsFor(100, 105)).toBe(6);
    expect(h.tracker.confirmationsFor(100, 1000)).toBe(REQUIRED);
    expect(h.tracker.confirmationsFor(100, 99)).toBe(0);
  });

  it('emits an update only when the confirmation count actually changes', async () => {
    h.tracker.add(tx({ block_number: 100, confirmations: 1 }));

    await h.tracker.onHead(100); // всё ещё 1 подтверждение
    expect(h.reports).toHaveLength(0);

    const updates = await h.tracker.onHead(103); // 4 подтверждения
    expect(updates).toHaveLength(1);
    expect(updates[0]).toMatchObject({ confirmations: 4, status: 'detected' });
    expect(h.reports).toHaveLength(1);

    await h.tracker.onHead(103); // тот же head — ничего нового
    expect(h.reports).toHaveLength(1);
  });

  it('confirms once the threshold is reached and the receipt checks out', async () => {
    h.tracker.add(tx({ block_number: 100 }));

    await h.tracker.onHead(105);
    expect(h.tracker.size).toBe(1);
    expect(h.verify).not.toHaveBeenCalled();

    const updates = await h.tracker.onHead(111); // 111 - 100 + 1 = 12 = required
    expect(h.verify).toHaveBeenCalledTimes(1);
    expect(updates[0]).toMatchObject({ status: 'confirmed', confirmations: REQUIRED });
    expect(h.tracker.size).toBe(0);

    // после подтверждения ничего больше не шлём
    await h.tracker.onHead(200);
    expect(h.reports).toHaveLength(2);
  });

  it('marks a transaction orphaned when verification says the chain dropped it', async () => {
    const o = harness('orphaned');
    o.tracker.add(tx({ block_number: 100 }));

    const updates = await o.tracker.onHead(111);
    expect(updates[0]).toMatchObject({ status: 'orphaned' });
    expect(o.tracker.size).toBe(0);
  });

  it('keeps waiting while verification is inconclusive, then gives up as orphaned', async () => {
    const u = harness('unknown');
    u.tracker.add(tx({ block_number: 100 }));

    await u.tracker.onHead(111);
    expect(u.tracker.size).toBe(1); // ждём, receipt ещё не виден

    for (let head = 112; head < 140 && u.tracker.size > 0; head += 1) {
      await u.tracker.onHead(head);
    }
    expect(u.tracker.size).toBe(0);
    expect(u.reports.at(-1)![0]).toMatchObject({ status: 'orphaned' });
  });

  it('survives a throwing verifier (treated as inconclusive, loop never breaks)', async () => {
    const t = harness(() => {
      throw new Error('rpc down');
    });
    t.tracker.add(tx({ block_number: 100 }));
    await expect(t.tracker.onHead(111)).resolves.toBeDefined();
    expect(t.tracker.size).toBe(1);
  });

  it('batches updates for several pending transactions into one report call', async () => {
    h.tracker.add(tx({ tx_hash: '0xaa', block_number: 100 }));
    h.tracker.add(tx({ tx_hash: '0xbb', block_number: 101 }));
    h.tracker.add(tx({ tx_hash: '0xcc', block_number: 102 }));

    const updates = await h.tracker.onHead(104);
    expect(updates).toHaveLength(3);
    expect(h.reports).toHaveLength(1);
    expect(h.reports[0]).toHaveLength(3);
    expect(updates.map((u) => u.confirmations)).toEqual([5, 4, 3]);
  });

  it('revalidates the rewound range on reorg and orphans what vanished', async () => {
    const o = harness('orphaned');
    o.tracker.add(tx({ tx_hash: '0xaa', block_number: 90 }));
    o.tracker.add(tx({ tx_hash: '0xbb', block_number: 120 }));

    const updates = await o.tracker.revalidateFrom(100);
    expect(updates).toHaveLength(1);
    expect(updates[0]).toMatchObject({ tx_hash: '0xbb', status: 'orphaned' });
    expect(o.tracker.size).toBe(1); // 0xaa вне зоны реорга — остаётся
  });

  it('honours a raised confirmations_required coming from config refresh', async () => {
    h.tracker.add(tx({ block_number: 100 }));
    h.tracker.setConfirmationsRequired(30);
    expect(h.tracker.confirmationsRequired).toBe(30);

    await h.tracker.onHead(111);
    expect(h.verify).not.toHaveBeenCalled();
    expect(h.tracker.size).toBe(1);
  });

  it('round-trips pending transactions through snapshot/restore (state.json)', async () => {
    h.tracker.add(tx({ tx_hash: '0xaa', block_number: 100 }));
    h.tracker.add(tx({ tx_hash: '0xbb', block_number: 101 }));

    const snapshot = JSON.parse(JSON.stringify(h.tracker.snapshot())) as PendingTx[];
    expect(snapshot).toHaveLength(2);

    const revived = harness();
    revived.tracker.restore(snapshot);
    expect(revived.tracker.size).toBe(2);
    expect(revived.tracker.has({ tx_hash: '0xaa', log_index: 0 })).toBe(true);

    const updates = await revived.tracker.onHead(105);
    expect(updates).toHaveLength(2);
  });

  it('does nothing when there is nothing pending', async () => {
    expect(await h.tracker.onHead(1000)).toEqual([]);
    expect(h.reports).toHaveLength(0);
  });
});
