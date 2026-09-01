import type { AppConfig } from '../config.js';
import { rpcUrlFor } from '../config.js';
import type { Logger } from '../logger.js';
import type { BackendClient } from '../backend/client.js';
import type { StateStore } from '../state/store.js';
import { ALL_NETWORKS, type NetworkCode, type NetworkConfig, type NetworkHealth } from '../types.js';
import type { Scanner } from './base.js';
import { WatchAddressSet } from './addresses.js';
import { EvmScanner } from './evm.js';
import { TronScanner } from './tron.js';
import { TronClient } from './tronClient.js';

const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

export class RescanError extends Error {}

interface Runtime {
  scanner: Scanner;
  addresses: WatchAddressSet;
  config: NetworkConfig;
  loopStopped: Promise<void>;
}

/**
 * Оркестратор: обновляет конфиг (60с) и адреса (10с), крутит воркеры сетей
 * и шлёт heartbeat (15с). Ни одна ошибка сети не должна ронять процесс (SPEC §7).
 */
export class ScannerManager {
  private readonly runtimes = new Map<NetworkCode, Runtime>();
  private readonly addressSets = new Map<NetworkCode, WatchAddressSet>();
  private lastConfig: NetworkConfig[] = [];
  private stopped = false;
  private timers: NodeJS.Timeout[] = [];
  private started = false;

  constructor(
    private readonly cfg: AppConfig,
    private readonly backend: BackendClient,
    private readonly store: StateStore,
    private readonly log: Logger,
  ) {}

  async start(): Promise<void> {
    if (this.started) return;
    this.started = true;

    // Backend может подниматься дольше нас — ретраим бесконечно, не выходя из процесса.
    await this.refreshConfigUntilSuccess();

    this.schedule(() => void this.refreshConfig(), this.cfg.configRefreshMs);
    this.schedule(() => void this.refreshAddresses(), this.cfg.addressRefreshMs);
    this.schedule(() => void this.sendHeartbeats(), this.cfg.heartbeatMs);

    void this.refreshAddresses();
  }

  private schedule(fn: () => void, intervalMs: number): void {
    const timer = setInterval(fn, intervalMs);
    timer.unref();
    this.timers.push(timer);
  }

  private async refreshConfigUntilSuccess(): Promise<void> {
    let attempt = 0;
    while (!this.stopped) {
      try {
        await this.refreshConfig(true);
        return;
      } catch (err) {
        attempt += 1;
        const delay = Math.min(2000 * 2 ** Math.min(attempt - 1, 4), 30_000);
        this.log.warn(
          { attempt, delayMs: delay, err: (err as Error).message },
          'initial config fetch failed, backend may still be starting — retrying',
        );
        await sleep(delay);
      }
    }
  }

  private async refreshConfig(rethrow = false): Promise<void> {
    try {
      const config = await this.backend.getConfig();
      this.lastConfig = config.networks ?? [];
      this.syncRuntimes();
    } catch (err) {
      this.log.error({ err: (err as Error).message }, 'config refresh failed');
      if (rethrow) throw err;
    }
  }

  private syncRuntimes(): void {
    for (const netConfig of this.lastConfig) {
      const code = netConfig.code;
      if (!ALL_NETWORKS.includes(code)) continue;

      const existing = this.runtimes.get(code);
      if (existing) {
        existing.config = netConfig;
        existing.scanner.applyConfig(netConfig);
        continue;
      }

      if (!netConfig.is_enabled) continue;

      const scanner = this.createScanner(code, netConfig);
      if (!scanner) continue;

      const addresses = new WatchAddressSet(code);
      const runtime: Runtime = {
        scanner,
        addresses,
        config: netConfig,
        loopStopped: Promise.resolve(),
      };
      this.runtimes.set(code, runtime);
      runtime.loopStopped = this.runLoop(runtime);
      this.log.info({ network: code, confirmations: netConfig.confirmations_required }, 'scanner started');
    }
  }

  private createScanner(code: NetworkCode, netConfig: NetworkConfig): Scanner | null {
    const addresses = this.addressSets.get(code) ?? new WatchAddressSet(code);

    if (code === 'tron') {
      if (!this.cfg.tronApiUrl) {
        this.log.error({ network: code }, 'TRON_API_URL is not set, tron scanning disabled');
        return null;
      }
      const client = new TronClient(this.cfg.tronApiUrl, this.cfg.tronApiKey, this.cfg.requestTimeoutMs);
      const scanner = new TronScanner(
        netConfig,
        client,
        this.cfg,
        this.backend,
        this.store,
        addresses,
        this.log.child({ network: 'tron' }),
      );
      this.attachAddresses(code, addresses);
      return scanner;
    }

    const rpcUrl = rpcUrlFor(this.cfg, code);
    if (!rpcUrl) {
      this.log.error({ network: code }, 'RPC url is not set, scanning disabled for this network');
      return null;
    }
    const scanner = new EvmScanner(
      code,
      netConfig,
      rpcUrl,
      this.cfg,
      this.backend,
      this.store,
      addresses,
      this.log.child({ network: code }),
    );
    this.attachAddresses(code, addresses);
    return scanner;
  }

  private attachAddresses(code: NetworkCode, set: WatchAddressSet): void {
    this.addressSets.set(code, set);
  }

  private async refreshAddresses(): Promise<void> {
    for (const [code, set] of this.addressSets) {
      try {
        const addresses = await this.backend.getWatchAddresses(code);
        set.replace(addresses);
      } catch (err) {
        this.log.warn({ network: code, err: (err as Error).message }, 'watch-addresses refresh failed');
      }
    }
  }

  private async sendHeartbeats(): Promise<void> {
    for (const netConfig of this.lastConfig) {
      const runtime = this.runtimes.get(netConfig.code);
      const scanner = runtime?.scanner;
      try {
        await this.backend.heartbeat({
          network: netConfig.code,
          last_scanned_block: scanner?.lastScannedBlock() ?? null,
          head_block: scanner?.headBlock() ?? null,
          healthy: scanner ? scanner.healthy() : false,
          error: scanner ? scanner.lastError() : 'scanner not running',
        });
      } catch (err) {
        this.log.warn({ network: netConfig.code, err: (err as Error).message }, 'heartbeat failed');
      }
    }
  }

  private async runLoop(runtime: Runtime): Promise<void> {
    let backoff = this.cfg.pollIntervalMs;
    while (!this.stopped) {
      const before = Date.now();

      if (!runtime.config.is_enabled) {
        // Сеть выключили в админке — воркер живёт, но не сканирует.
        await sleep(this.cfg.pollIntervalMs);
        continue;
      }

      await runtime.scanner.tick();

      const lag = runtime.scanner.health().lag ?? 0;
      if (runtime.scanner.lastError() !== null) {
        // Ошибка RPC — экспоненциальный backoff.
        backoff = Math.min(backoff * 2, 60_000);
      } else if (lag > 1) {
        // Отстаём от head без ошибок — режим догона: не ждём полный poll interval.
        backoff = 250;
      } else {
        backoff = this.cfg.pollIntervalMs;
      }

      const elapsed = Date.now() - before;
      await sleep(Math.max(250, backoff - elapsed));
    }
  }

  healthSnapshot(): NetworkHealth[] {
    const known = new Map<NetworkCode, NetworkHealth>();

    for (const netConfig of this.lastConfig) {
      const runtime = this.runtimes.get(netConfig.code);
      known.set(
        netConfig.code,
        runtime?.scanner.health() ?? {
          code: netConfig.code,
          enabled: netConfig.is_enabled,
          headBlock: null,
          lastScannedBlock: netConfig.last_scanned_block ?? null,
          lag: null,
          pendingTxs: 0,
          lastError: netConfig.is_enabled ? 'scanner not running' : null,
          updatedAt: null,
        },
      );
    }

    for (const code of ALL_NETWORKS) {
      if (!known.has(code)) {
        known.set(code, {
          code,
          enabled: false,
          headBlock: null,
          lastScannedBlock: null,
          lag: null,
          pendingTxs: 0,
          lastError: null,
          updatedAt: null,
        });
      }
    }

    return [...known.values()];
  }

  rescan(network: NetworkCode, fromBlock: number): void {
    const runtime = this.runtimes.get(network);
    if (!runtime) {
      throw new RescanError(`no active scanner for network ${network}`);
    }
    runtime.scanner.requestRescan(fromBlock);
  }

  async stop(): Promise<void> {
    this.stopped = true;
    for (const timer of this.timers) clearInterval(timer);
    this.timers = [];
    await Promise.allSettled([...this.runtimes.values()].map((r) => r.scanner.close()));
  }
}
