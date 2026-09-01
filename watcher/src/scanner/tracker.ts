import type { NetworkCode, TransactionReport } from '../types.js';

/** Результат проверки транзакции в цепочке при достижении порога подтверждений. */
export type VerifyResult = 'confirmed' | 'orphaned' | 'unknown';

export interface PendingTx extends TransactionReport {
  /** Сколько раз подряд verify() вернул 'unknown' (защита от вечного зависания). */
  verifyAttempts?: number;
}

export interface TrackerOptions {
  network: NetworkCode;
  confirmationsRequired: number;
  /** Проверка в цепочке (EVM: receipt+blockHash; Tron: gettransactioninfobyid). */
  verify: (tx: PendingTx) => Promise<VerifyResult>;
  /** Отправка batch-обновлений в backend. */
  report: (txs: TransactionReport[]) => Promise<void>;
  /** После стольких неудачных verify подряд считаем транзакцию orphaned. */
  maxVerifyAttempts?: number;
  onChange?: () => void;
}

export function txKey(tx: Pick<TransactionReport, 'tx_hash' | 'log_index'>): string {
  return `${tx.tx_hash.toLowerCase()}:${tx.log_index}`;
}

/**
 * Конечный автомат подтверждений (SPEC §7.4).
 *
 *   detected --(confirmations растут)--> detected(N) --(N >= required && verify ok)--> confirmed
 *                                                    \--(verify says reorg/fail)---> orphaned
 *
 * Никакой сети внутри — вся работа с цепочкой инжектируется через verify/report,
 * поэтому автомат полностью юнит-тестируем.
 */
export class ConfirmationTracker {
  private readonly pending = new Map<string, PendingTx>();
  private readonly opts: Required<Omit<TrackerOptions, 'onChange'>> & { onChange?: () => void };

  constructor(options: TrackerOptions) {
    this.opts = {
      maxVerifyAttempts: 20,
      ...options,
    } as Required<Omit<TrackerOptions, 'onChange'>> & { onChange?: () => void };
  }

  get network(): NetworkCode {
    return this.opts.network;
  }

  get size(): number {
    return this.pending.size;
  }

  get confirmationsRequired(): number {
    return this.opts.confirmationsRequired;
  }

  setConfirmationsRequired(value: number): void {
    if (Number.isInteger(value) && value > 0) {
      this.opts.confirmationsRequired = value;
    }
  }

  has(tx: Pick<TransactionReport, 'tx_hash' | 'log_index'>): boolean {
    return this.pending.has(txKey(tx));
  }

  /**
   * Кладёт tx в отслеживание. Возвращает true, если это новая транзакция
   * (её нужно немедленно отправить как `detected`), false — если уже известна.
   * При повторном обнаружении в другом блоке (реорг) метаданные блока обновляются.
   */
  add(tx: PendingTx): boolean {
    const key = txKey(tx);
    const existing = this.pending.get(key);
    if (!existing) {
      this.pending.set(key, { ...tx, status: 'detected', verifyAttempts: 0 });
      this.opts.onChange?.();
      return true;
    }
    if (existing.block_hash !== tx.block_hash || existing.block_number !== tx.block_number) {
      this.pending.set(key, { ...tx, status: 'detected', verifyAttempts: 0 });
      this.opts.onChange?.();
      return true;
    }
    return false;
  }

  remove(tx: Pick<TransactionReport, 'tx_hash' | 'log_index'>): void {
    if (this.pending.delete(txKey(tx))) this.opts.onChange?.();
  }

  list(): PendingTx[] {
    return [...this.pending.values()];
  }

  snapshot(): PendingTx[] {
    return this.list().map((tx) => ({ ...tx }));
  }

  restore(txs: PendingTx[] | undefined): void {
    this.pending.clear();
    for (const tx of txs ?? []) {
      if (tx && typeof tx.tx_hash === 'string') {
        this.pending.set(txKey(tx), { ...tx, verifyAttempts: tx.verifyAttempts ?? 0 });
      }
    }
  }

  confirmationsFor(blockNumber: number, head: number): number {
    const raw = head - blockNumber + 1;
    if (raw < 0) return 0;
    return Math.min(raw, this.opts.confirmationsRequired);
  }

  /**
   * Пересчёт на новом head. Отправляет batch-обновление ТОЛЬКО если что-то изменилось,
   * и не более одного раза на новый head (вызывается ровно раз за head).
   */
  async onHead(head: number): Promise<TransactionReport[]> {
    if (this.pending.size === 0) return [];

    const updates: TransactionReport[] = [];
    let changed = false;

    for (const tx of this.list()) {
      const confirmations = this.confirmationsFor(tx.block_number, head);

      if (confirmations >= this.opts.confirmationsRequired) {
        let result: VerifyResult;
        try {
          result = await this.opts.verify(tx);
        } catch {
          result = 'unknown';
        }

        if (result === 'confirmed') {
          this.pending.delete(txKey(tx));
          changed = true;
          updates.push({ ...stripMeta(tx), confirmations, status: 'confirmed' });
          continue;
        }
        if (result === 'orphaned') {
          this.pending.delete(txKey(tx));
          changed = true;
          updates.push({ ...stripMeta(tx), confirmations, status: 'orphaned' });
          continue;
        }

        // unknown: приёмник ещё не видит receipt — ждём следующий head.
        const attempts = (tx.verifyAttempts ?? 0) + 1;
        if (attempts >= this.opts.maxVerifyAttempts) {
          this.pending.delete(txKey(tx));
          changed = true;
          updates.push({ ...stripMeta(tx), confirmations, status: 'orphaned' });
          continue;
        }
        tx.verifyAttempts = attempts;
        changed = true;
        if (tx.confirmations !== confirmations) {
          tx.confirmations = confirmations;
          updates.push({ ...stripMeta(tx), confirmations, status: 'detected' });
        }
        continue;
      }

      if (confirmations !== tx.confirmations) {
        tx.confirmations = confirmations;
        changed = true;
        updates.push({ ...stripMeta(tx), confirmations, status: 'detected' });
      }
    }

    if (changed) this.opts.onChange?.();
    if (updates.length > 0) {
      await this.opts.report(updates);
    }
    return updates;
  }

  /**
   * Реорг: принудительно перепроверить всё, что лежит в блоках >= fromBlock.
   * Не подтвердившиеся уходят в `orphaned` (SPEC §7.6).
   */
  async revalidateFrom(fromBlock: number): Promise<TransactionReport[]> {
    const affected = this.list().filter((tx) => tx.block_number >= fromBlock);
    if (affected.length === 0) return [];

    const updates: TransactionReport[] = [];
    for (const tx of affected) {
      let result: VerifyResult;
      try {
        result = await this.opts.verify(tx);
      } catch {
        result = 'unknown';
      }
      if (result === 'orphaned') {
        this.pending.delete(txKey(tx));
        updates.push({ ...stripMeta(tx), status: 'orphaned' });
      }
    }

    if (updates.length > 0) {
      this.opts.onChange?.();
      await this.opts.report(updates);
    }
    return updates;
  }
}

function stripMeta(tx: PendingTx): TransactionReport {
  const { verifyAttempts: _ignored, ...rest } = tx;
  return rest;
}
