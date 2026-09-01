import type { NetworkCode, NetworkConfig, NetworkHealth } from '../types.js';

export interface Scanner {
  readonly network: NetworkCode;
  applyConfig(cfg: NetworkConfig): void;
  tick(): Promise<void>;
  health(): NetworkHealth;
  requestRescan(fromBlock: number): void;
  pendingCount(): number;
  headBlock(): number | null;
  lastScannedBlock(): number | null;
  healthy(): boolean;
  lastError(): string | null;
  close(): Promise<void>;
}

/** Общая учётка head/lastScanned/ошибок для здоровья и heartbeat (SPEC §7.5). */
export class ScannerHealthState {
  head: number | null = null;
  lastScanned: number | null = null;
  consecutiveErrors = 0;
  errorMessage: string | null = null;
  updatedAt: string | null = null;

  constructor(
    private readonly maxLagBlocks: number,
    private readonly maxConsecutiveErrors: number,
  ) {}

  ok(): void {
    this.consecutiveErrors = 0;
    this.errorMessage = null;
    this.updatedAt = new Date().toISOString();
  }

  fail(err: unknown): void {
    this.consecutiveErrors += 1;
    this.errorMessage = err instanceof Error ? err.message : String(err);
    this.updatedAt = new Date().toISOString();
  }

  get lag(): number | null {
    if (this.head === null || this.lastScanned === null) return null;
    return Math.max(0, this.head - this.lastScanned);
  }

  get healthy(): boolean {
    if (this.consecutiveErrors >= this.maxConsecutiveErrors) return false;
    const lag = this.lag;
    if (lag !== null && lag > this.maxLagBlocks) return false;
    return true;
  }
}

export function healthPayload(
  network: NetworkCode,
  enabled: boolean,
  state: ScannerHealthState,
  pendingTxs: number,
): NetworkHealth {
  return {
    code: network,
    enabled,
    headBlock: state.head,
    lastScannedBlock: state.lastScanned,
    lag: state.lag,
    pendingTxs,
    lastError: state.errorMessage,
    updatedAt: state.updatedAt,
  };
}
