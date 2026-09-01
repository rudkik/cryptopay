import { tronBase58ToHex } from '../tronAddress.js';
import type { NetworkCode, WatchAddress } from '../types.js';

/**
 * Кеш отслеживаемых адресов сети (обновляется раз в 10с из /api/internal/watch-addresses).
 * EVM хранится в нижнем регистре; Tron — точный base58 плюс карта hex(41…) -> base58.
 */
export class WatchAddressSet {
  private set = new Set<string>();
  private hexIndex = new Map<string, string>();
  private loadedAt: number | null = null;

  constructor(private readonly network: NetworkCode) {}

  get isTron(): boolean {
    return this.network === 'tron';
  }

  get size(): number {
    return this.set.size;
  }

  get loaded(): boolean {
    return this.loadedAt !== null;
  }

  replace(addresses: WatchAddress[]): void {
    const set = new Set<string>();
    const hexIndex = new Map<string, string>();

    for (const item of addresses) {
      const raw = typeof item?.address === 'string' ? item.address.trim() : '';
      if (!raw) continue;

      if (this.isTron) {
        set.add(raw);
        try {
          hexIndex.set(tronBase58ToHex(raw).toLowerCase(), raw);
        } catch {
          // невалидный адрес из БД — пропускаем, но не роняем цикл
        }
      } else {
        set.add(raw.toLowerCase());
      }
    }

    this.set = set;
    this.hexIndex = hexIndex;
    this.loadedAt = Date.now();
  }

  has(address: string): boolean {
    if (!address) return false;
    return this.isTron ? this.set.has(address) : this.set.has(address.toLowerCase());
  }

  hasHex(hexAddress: string): boolean {
    return this.hexIndex.has(hexAddress.toLowerCase());
  }

  fromHex(hexAddress: string): string | undefined {
    return this.hexIndex.get(hexAddress.toLowerCase());
  }

  values(): string[] {
    return [...this.set];
  }
}
