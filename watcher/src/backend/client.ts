import type { AppConfig } from '../config.js';
import type { Logger } from '../logger.js';
import type {
  BackendConfig,
  HeartbeatReport,
  NetworkCode,
  TransactionReport,
  WatchAddress,
} from '../types.js';

export class BackendError extends Error {
  constructor(
    message: string,
    public readonly status?: number,
    public readonly body?: string,
  ) {
    super(message);
    this.name = 'BackendError';
  }

  /** 4xx (кроме 408/429) повторять бессмысленно — это наша ошибка контракта. */
  get retryable(): boolean {
    if (this.status === undefined) return true; // сетевая ошибка / таймаут
    if (this.status === 408 || this.status === 429) return true;
    return this.status >= 500;
  }
}

const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

/**
 * HTTP-клиент внутреннего API Laravel (SPEC §6.5).
 * Все методы либо возвращают результат, либо бросают BackendError — вызывающий цикл
 * обязан ловить и продолжать работу (никогда не падать).
 */
export class BackendClient {
  private readonly baseUrl: string;
  private readonly token: string;
  private readonly retries: number;
  private readonly timeoutMs: number;

  constructor(
    cfg: AppConfig,
    private readonly log: Logger,
  ) {
    this.baseUrl = cfg.backendUrl;
    this.token = cfg.internalToken;
    this.retries = cfg.backendRetries;
    this.timeoutMs = cfg.requestTimeoutMs;
  }

  private async request<T>(method: 'GET' | 'POST', pathname: string, body?: unknown): Promise<T> {
    const url = `${this.baseUrl}${pathname}`;
    let lastError: BackendError = new BackendError('request never executed');

    for (let attempt = 0; attempt <= this.retries; attempt += 1) {
      if (attempt > 0) {
        // экспоненциальный backoff с джиттером: 500ms, 1s, 2s, 4s, 8s (cap 30s)
        const delay = Math.min(500 * 2 ** (attempt - 1), 30_000);
        await sleep(delay + Math.floor(Math.random() * 250));
      }

      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), this.timeoutMs);
      try {
        const res = await fetch(url, {
          method,
          headers: {
            'X-Internal-Token': this.token,
            Accept: 'application/json',
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
          },
          ...(body === undefined ? {} : { body: JSON.stringify(body) }),
          signal: controller.signal,
        });

        if (!res.ok) {
          const text = (await res.text().catch(() => '')).slice(0, 2000);
          lastError = new BackendError(`${method} ${pathname} -> HTTP ${res.status}`, res.status, text);
          if (!lastError.retryable) throw lastError;
          this.log.warn(
            { url: pathname, status: res.status, attempt, body: text.slice(0, 300) },
            'backend request failed, retrying',
          );
          continue;
        }

        if (res.status === 204) return undefined as T;
        const text = await res.text();
        return (text ? JSON.parse(text) : undefined) as T;
      } catch (err) {
        if (err instanceof BackendError) throw err;
        const message = err instanceof Error ? err.message : String(err);
        lastError = new BackendError(`${method} ${pathname} -> ${message}`);
        this.log.warn({ url: pathname, attempt, err: message }, 'backend request error, retrying');
      } finally {
        clearTimeout(timer);
      }
    }

    throw lastError;
  }

  async getConfig(): Promise<BackendConfig> {
    const raw = await this.request<BackendConfig>('GET', '/api/internal/config');
    if (!raw || !Array.isArray(raw.networks)) {
      throw new BackendError('malformed /api/internal/config response');
    }
    return raw;
  }

  async getWatchAddresses(network: NetworkCode, updatedSince?: string): Promise<WatchAddress[]> {
    const qs = new URLSearchParams({ network });
    if (updatedSince) qs.set('updated_since', updatedSince);
    const raw = await this.request<{ addresses?: WatchAddress[] }>(
      'GET',
      `/api/internal/watch-addresses?${qs.toString()}`,
    );
    // Раньше здесь возвращался пустой массив: тело без `addresses` (прокси отдал
    // HTML, backend поменял контракт, обрезанный JSON) молча стирало ВЕСЬ список
    // отслеживаемых адресов. Сканер при этом считает адреса «загруженными» и
    // проносится по блокам, помечая их просканированными, — депозиты в этом
    // окне теряются навсегда. Пустой список допустим только явный.
    if (!raw || !Array.isArray(raw.addresses)) {
      throw new BackendError(`malformed /api/internal/watch-addresses response for ${network}`);
    }
    return raw.addresses;
  }

  async postTransaction(tx: TransactionReport): Promise<void> {
    await this.request('POST', '/api/internal/transactions', tx);
  }

  async postTransactionBatch(transactions: TransactionReport[]): Promise<void> {
    if (transactions.length === 0) return;
    // Держим тело запроса в разумных пределах.
    const CHUNK = 200;
    for (let i = 0; i < transactions.length; i += CHUNK) {
      await this.request('POST', '/api/internal/transactions/batch', {
        transactions: transactions.slice(i, i + CHUNK),
      });
    }
  }

  async heartbeat(report: HeartbeatReport): Promise<void> {
    await this.request('POST', '/api/internal/heartbeat', report);
  }
}
