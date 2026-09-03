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
      const parsed: unknown = JSON.parse(raw);
      const networks = sanitizeNetworks(parsed);
      if (networks === null) {
        this.log.warn(
          { file: this.file },
          'state file has an unexpected shape, ignoring it — стартовая высота будет взята ' +
            'из last_scanned_block backend (/api/internal/config)',
        );
      } else {
        this.state = {
          version: STATE_VERSION,
          updatedAt:
            typeof (parsed as WatcherState).updatedAt === 'string'
              ? (parsed as WatcherState).updatedAt
              : new Date().toISOString(),
          networks,
        };
        this.log.info({ file: this.file, networks: Object.keys(networks) }, 'state loaded');
      }
    } catch (err) {
      const code = (err as NodeJS.ErrnoException).code;
      if (code !== 'ENOENT') {
        // Битый JSON (обрыв питания на записи, ручная правка) не должен мешать
        // старту: продолжаем с пустым состоянием, backend отдаст last_scanned_block.
        this.log.warn(
          { file: this.file, err: (err as Error).message },
          'cannot read state file, starting fresh (backend last_scanned_block will be used)',
        );
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

const NETWORK_CODES: readonly NetworkCode[] = ['ethereum', 'bsc', 'tron'];

function isFiniteInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && Number.isFinite(value);
}

/**
 * Файл состояния лежит на диске и переживает рестарты — доверять ему как коду
 * нельзя. Всё, что не совпало по форме, отбрасывается: одна строка вместо числа
 * в lastScannedBlock раньше ломала арифметику скана, а битый recentBlocks[].hash
 * ронял checkReorg на каждом тике.
 *
 * Возвращает null, если файл вообще не похож на состояние watcher'а.
 */
function sanitizeNetworks(parsed: unknown): Partial<Record<NetworkCode, NetworkState>> | null {
  if (!parsed || typeof parsed !== 'object') return null;
  const networks = (parsed as { networks?: unknown }).networks;
  if (!networks || typeof networks !== 'object') return null;

  const out: Partial<Record<NetworkCode, NetworkState>> = {};

  for (const code of NETWORK_CODES) {
    const entry = (networks as Record<string, unknown>)[code];
    if (!entry || typeof entry !== 'object') continue;
    const raw = entry as Partial<NetworkState>;

    const lastScannedBlock = isFiniteInt(raw.lastScannedBlock) && raw.lastScannedBlock >= 0 ? raw.lastScannedBlock : null;

    const recentBlocks = Array.isArray(raw.recentBlocks)
      ? raw.recentBlocks.filter(
          (b): b is BlockRef =>
            !!b && typeof b === 'object' && isFiniteInt((b as BlockRef).number) && typeof (b as BlockRef).hash === 'string',
        )
      : [];

    const pending = Array.isArray(raw.pending)
      ? raw.pending.filter(
          (tx): tx is PendingTx =>
            !!tx &&
            typeof tx === 'object' &&
            typeof (tx as PendingTx).tx_hash === 'string' &&
            isFiniteInt((tx as PendingTx).log_index) &&
            isFiniteInt((tx as PendingTx).block_number) &&
            typeof (tx as PendingTx).block_hash === 'string',
        )
      : [];

    out[code] = { lastScannedBlock, recentBlocks, pending };
  }

  return out;
}
