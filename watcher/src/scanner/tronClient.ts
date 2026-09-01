import type { TronBlock } from './tronDecode.js';

export interface TronTransactionInfo {
  id?: string;
  blockNumber?: number;
  receipt?: { result?: string };
  result?: string;
  [key: string]: unknown;
}

/** Максимум блоков за одну итерацию скана (запрашиваются по одному через wallet/getblockbynum). */
export const TRON_MAX_BLOCKS_PER_CALL = 10;
/** Пауза между последовательными getblockbynum (мс). */
const TRON_REQUEST_GAP_MS = 250;
/** Повторов при HTTP 429. */
const TRON_RATE_LIMIT_RETRIES = 4;

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/** Тонкий HTTP-клиент TronGrid (SPEC §7.3). */
export class TronClient {
  constructor(
    private readonly baseUrl: string,
    private readonly apiKey: string,
    private readonly timeoutMs = 30_000,
  ) {}

  private async post<T>(pathname: string, body: Record<string, unknown>): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const res = await fetch(`${this.baseUrl}/${pathname}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(this.apiKey ? { 'TRON-PRO-API-KEY': this.apiKey } : {}),
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });
      if (!res.ok) {
        const text = (await res.text().catch(() => '')).slice(0, 500);
        throw new Error(`TronGrid ${pathname} -> HTTP ${res.status}: ${text}`);
      }
      const json = (await res.json()) as T & { Error?: string; error?: string };
      if (json && typeof json === 'object' && (json.Error || json.error)) {
        throw new Error(`TronGrid ${pathname} -> ${json.Error ?? json.error}`);
      }
      return json;
    } finally {
      clearTimeout(timer);
    }
  }

  async getNowBlock(): Promise<TronBlock> {
    return this.post<TronBlock>('wallet/getnowblock', {});
  }

  async getSolidityNowBlock(): Promise<TronBlock> {
    return this.post<TronBlock>('walletsolidity/getnowblock', {});
  }

  async getBlockByNum(num: number): Promise<TronBlock | null> {
    const res = await this.post<TronBlock>('wallet/getblockbynum', { num });
    if (!res || !res.block_header) return null;
    return res;
  }

  /**
   * Блоки в полуинтервале [startNum, endNum), по порядку.
   * TronGrid больше не поддерживает wallet/getblockbylimit (HTTP 405), поэтому блоки
   * запрашиваются по одному через getblockbynum — последовательно, с паузой между
   * запросами и backoff на 429 (бесплатный тариф TronGrid без TRON_API_KEY очень строгий).
   * Если блок ещё не доступен на ноде или лимит исчерпан — возвращаем непрерывный префикс.
   */
  async getBlockByLimit(startNum: number, endNum: number): Promise<TronBlock[]> {
    if (endNum - startNum > TRON_MAX_BLOCKS_PER_CALL) {
      throw new Error(`tron block range too large: ${startNum}..${endNum}`);
    }
    const blocks: TronBlock[] = [];
    for (let n = startNum; n < endNum; n++) {
      const block = await this.getBlockWithBackoff(n);
      if (!block) break;
      blocks.push(block);
      if (n + 1 < endNum) await sleep(TRON_REQUEST_GAP_MS);
    }
    return blocks;
  }

  private async getBlockWithBackoff(num: number): Promise<TronBlock | null> {
    let delay = 1_000;
    for (let attempt = 0; ; attempt++) {
      try {
        return await this.getBlockByNum(num);
      } catch (err) {
        const msg = err instanceof Error ? err.message : String(err);
        if (!/HTTP 429|rate exceeded|rate limit/i.test(msg) || attempt >= TRON_RATE_LIMIT_RETRIES) throw err;
        await sleep(delay);
        delay = Math.min(delay * 2, 8_000);
      }
    }
  }

  async getTransactionInfoById(txId: string): Promise<TronTransactionInfo | null> {
    const res = await this.post<TronTransactionInfo>('wallet/gettransactioninfobyid', { value: txId });
    if (!res || Object.keys(res).length === 0) return null;
    return res;
  }
}
