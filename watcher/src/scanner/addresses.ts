import { tronBase58ToHex } from '../tronAddress.js';
import type { NetworkCode, WatchAddress } from '../types.js';

const EVM_ADDRESS = /^0[xX][0-9a-fA-F]{40}$/;

/**
 * Кеш отслеживаемых адресов сети (обновляется раз в 10с из /api/internal/watch-addresses).
 * EVM хранится в нижнем регистре; Tron — точный base58 плюс карта hex(41…) -> base58.
 */
export class WatchAddressSet {
  private set = new Set<string>();
  private hexIndex = new Map<string, string>();
  private loadedAt: number | null = null;
  private rejected = 0;

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

  /** Сколько адресов последнего ответа backend не прошли валидацию. */
  get rejectedCount(): number {
    return this.rejected;
  }

  replace(addresses: WatchAddress[]): void {
    const set = new Set<string>();
    const hexIndex = new Map<string, string>();
    this.rejected = 0;

    for (const item of addresses) {
      const raw = typeof item?.address === 'string' ? item.address.trim() : '';
      if (!raw) continue;

      if (this.isTron) {
        try {
          hexIndex.set(tronBase58ToHex(raw).toLowerCase(), raw);
          set.add(raw);
        } catch {
          // невалидный адрес из БД — пропускаем, но не роняем цикл
          this.rejected += 1;
        }
      } else if (EVM_ADDRESS.test(raw)) {
        set.add(raw.toLowerCase());
      } else {
        // Невалидный адрес из БД уронил бы getAddress() внутри addressToTopic,
        // а вместе с ним и весь скан сети — до тех пор, пока строку не починят.
        this.rejected += 1;
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
