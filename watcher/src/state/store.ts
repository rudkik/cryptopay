import { randomBytes } from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import type { Logger } from '../logger.js';
import type { NetworkCode } from '../types.js';
import type { PendingTx } from '../scanner/tracker.js';

export interface BlockRef {
  number: number;
  hash: string;
}

export interface NetworkState {
  lastScannedBlock: number | null;
  recentBlocks: BlockRef[];
  pending: PendingTx[];
}

export interface WatcherState {
  version: number;
  updatedAt: string;
  networks: Partial<Record<NetworkCode, NetworkState>>;
}

const STATE_VERSION = 1;

export function emptyNetworkState(): NetworkState {
  return { lastScannedBlock: null, recentBlocks: [], pending: [] };
}

/**
 * Персистентное состояние в data/state.json.
 * Запись атомарная (temp + rename). Если каталог недоступен на запись (частый случай
 * с docker volume, смонтированным от root), работаем in-memory и громко логируем.
 */
export class StateStore {
  private state: WatcherState = { version: STATE_VERSION, updatedAt: new Date().toISOString(), networks: {} };
  private dirty = false;
  private writable = true;
  private writing: Promise<void> = Promise.resolve();
  private timer: NodeJS.Timeout | null = null;

  constructor(
    private readonly file: string,
    private readonly log: Logger,
    private readonly flushIntervalMs = 5000,
  ) {}

  async load(): Promise<void> {
    const dir = path.dirname(this.file);
    try {
      await fs.mkdir(dir, { recursive: true });
      await fs.access(dir, fs.constants.W_OK);
    } catch (err) {
      this.writable = false;
      const code = (err as NodeJS.ErrnoException).code;
      this.log.error(
        { dir, code, err: (err as Error).message },
        code === 'EACCES' || code === 'EPERM'
          ? 'DATA_DIR is not writable — состояние не сохранится между рестартами. ' +
              'Исправьте права на volume (chown node:node) или задайте другой DATA_DIR.'
          : 'cannot prepare DATA_DIR, state persistence disabled',
      );
    }

    try {
      const raw = await fs.readFile(this.file, 'utf8');
      const parsed = JSON.parse(raw) as WatcherState;
      if (parsed && typeof parsed === 'object' && parsed.networks) {
        this.state = {
          version: STATE_VERSION,
          updatedAt: parsed.updatedAt ?? new Date().toISOString(),
          networks: parsed.networks,
        };
        this.log.info({ file: this.file }, 'state loaded');
      }
    } catch (err) {
      const code = (err as NodeJS.ErrnoException).code;
      if (code !== 'ENOENT') {
        this.log.warn({ file: this.file, err: (err as Error).message }, 'cannot read state file, starting fresh');
      }
    }

    if (this.writable && this.timer === null) {
      this.timer = setInterval(() => {
        void this.flush();
      }, this.flushIntervalMs);
      this.timer.unref();
    }
  }

  get(network: NetworkCode): NetworkState {
    const existing = this.state.networks[network];
    if (existing) {
      existing.recentBlocks ??= [];
      existing.pending ??= [];
      return existing;
    }
    const created = emptyNetworkState();
    this.state.networks[network] = created;
    return created;
  }

  set(network: NetworkCode, next: NetworkState): void {
    this.state.networks[network] = next;
    this.dirty = true;
  }

  touch(): void {
    this.dirty = true;
  }

  snapshot(): WatcherState {
    return structuredClone(this.state);
  }

  async flush(force = false): Promise<void> {
    if (!this.writable) return;
    if (!this.dirty && !force) return;
    this.dirty = false;
    this.state.updatedAt = new Date().toISOString();
    const payload = JSON.stringify(this.state, null, 2);

    // Сериализуем записи, чтобы два flush'а не гонялись за один rename.
    this.writing = this.writing.then(async () => {
      const tmp = `${this.file}.${randomBytes(6).toString('hex')}.tmp`;
      try {
        await fs.writeFile(tmp, payload, { encoding: 'utf8', mode: 0o600 });
        await fs.rename(tmp, this.file);
      } catch (err) {
        this.dirty = true;
        const code = (err as NodeJS.ErrnoException).code;
        if (code === 'EACCES' || code === 'EPERM') {
          this.writable = false;
          this.log.error({ file: this.file, code }, 'state file not writable, persistence disabled');
        } else {
          this.log.warn({ file: this.file, err: (err as Error).message }, 'state flush failed');
        }
        await fs.rm(tmp, { force: true }).catch(() => undefined);
      }
    });

    await this.writing;
  }

  async close(): Promise<void> {
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
    await this.flush(true);
  }

  get persistent(): boolean {
    return this.writable;
  }
}
