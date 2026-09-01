import { JsonRpcProvider, Network, getAddress } from 'ethers';
import type { AppConfig } from '../config.js';
import type { Logger } from '../logger.js';
import type { BackendClient } from '../backend/client.js';
import type { StateStore, BlockRef } from '../state/store.js';
import type { NetworkCode, NetworkConfig, NetworkHealth, TokenConfig, TransactionReport } from '../types.js';
import { ScannerHealthState, healthPayload, type Scanner } from './base.js';
import { ConfirmationTracker, type PendingTx, type VerifyResult } from './tracker.js';
import { TRANSFER_TOPIC, addressToTopic, buildEvmReport, parseTransferLog, type RawLog } from './evmDecode.js';
import type { WatchAddressSet } from './addresses.js';

const ADDRESS_CHUNK = 500;

/** Признаки того, что провайдер не переварил диапазон блоков/объём логов. */
const RANGE_ERROR = /range|too large|too many|exceed|limit|more than .* results|query timeout|10000|block range/i;
const BEYOND_HEAD_ERROR = /beyond current head|block not found|header not found|unknown block/i;

export class EvmScanner implements Scanner {
  readonly network: NetworkCode;
  private provider: JsonRpcProvider;
  private netConfig: NetworkConfig;
  private readonly state: ScannerHealthState;
  private readonly tracker: ConfirmationTracker;
  private batchBlocks: number;
  private readonly maxBatchBlocks: number;
  private recentBlocks: BlockRef[] = [];
  private lastHeadProcessed: number | null = null;
  private rescanFrom: number | null = null;

  constructor(
    network: NetworkCode,
    netConfig: NetworkConfig,
    rpcUrl: string,
    private readonly cfg: AppConfig,
    private readonly backend: BackendClient,
    private readonly store: StateStore,
    private readonly addresses: WatchAddressSet,
    private readonly log: Logger,
  ) {
    this.network = network;
    this.netConfig = netConfig;
    this.maxBatchBlocks = cfg.evmBatchBlocks;
    this.batchBlocks = cfg.evmBatchBlocks;
    this.state = new ScannerHealthState(cfg.maxLagBlocks, cfg.maxConsecutiveErrors);

    const staticNetwork =
      netConfig.chain_id !== null && netConfig.chain_id !== undefined
        ? Network.from(netConfig.chain_id)
        : undefined;
    this.provider = new JsonRpcProvider(rpcUrl, staticNetwork, {
      staticNetwork: staticNetwork ?? true,
      batchMaxCount: 10,
    });

    this.tracker = new ConfirmationTracker({
      network,
      confirmationsRequired: netConfig.confirmations_required,
      verify: (tx) => this.verify(tx),
      report: (txs) => this.report(txs),
      onChange: () => this.persist(),
    });

    const persisted = this.store.get(network);
    this.recentBlocks = persisted.recentBlocks ?? [];
    this.tracker.restore(persisted.pending);
    this.state.lastScanned = persisted.lastScannedBlock;
    // last_scanned_block из backend имеет приоритет, если он дальше нашего.
    if (netConfig.last_scanned_block !== null && netConfig.last_scanned_block !== undefined) {
      if (this.state.lastScanned === null || netConfig.last_scanned_block > this.state.lastScanned) {
        this.state.lastScanned = netConfig.last_scanned_block;
      }
    }
  }

  applyConfig(next: NetworkConfig): void {
    this.netConfig = next;
    this.tracker.setConfirmationsRequired(next.confirmations_required);
  }

  private enabledTokens(): TokenConfig[] {
    return (this.netConfig.tokens ?? []).filter((t) => Boolean(t?.contract_address));
  }

  private tokenByContract(): Map<string, TokenConfig> {
    const map = new Map<string, TokenConfig>();
    for (const token of this.enabledTokens()) {
      try {
        map.set(getAddress(token.contract_address).toLowerCase(), token);
      } catch {
        this.log.warn({ network: this.network, contract: token.contract_address }, 'invalid token contract, skipped');
      }
    }
    return map;
  }

  private persist(): void {
    this.store.set(this.network, {
      lastScannedBlock: this.state.lastScanned,
      recentBlocks: this.recentBlocks,
      pending: this.tracker.snapshot(),
    });
  }

  private async report(txs: TransactionReport[]): Promise<void> {
    if (txs.length === 0) return;
    try {
      if (txs.length === 1 && txs[0]) {
        await this.backend.postTransaction(txs[0]);
      } else {
        await this.backend.postTransactionBatch(txs);
      }
    } catch (err) {
      // Не роняем цикл: транзакции останутся в pending и будут отправлены повторно.
      this.log.error(
        { network: this.network, count: txs.length, err: (err as Error).message },
        'failed to report transactions to backend',
      );
    }
  }

  private async verify(tx: PendingTx): Promise<VerifyResult> {
    let receipt: Awaited<ReturnType<JsonRpcProvider['getTransactionReceipt']>>;
    try {
      receipt = await this.provider.getTransactionReceipt(tx.tx_hash);
    } catch (err) {
      // Трекер трактует исключение как 'unknown' и повторит на следующем head,
      // но причину надо видеть в логах.
      this.log.warn(
        { network: this.network, txHash: tx.tx_hash, err: (err as Error).message },
        'receipt lookup failed, confirmation deferred',
      );
      throw err;
    }
    if (!receipt) return 'orphaned';
    if (receipt.status !== 1) return 'orphaned';
    if (receipt.blockHash.toLowerCase() !== tx.block_hash.toLowerCase()) return 'orphaned';
    return 'confirmed';
  }

  requestRescan(fromBlock: number): void {
    this.rescanFrom = Math.max(0, Math.floor(fromBlock));
    this.log.info({ network: this.network, fromBlock: this.rescanFrom }, 'rescan requested');
  }

  async tick(): Promise<void> {
    try {
      const head = await this.provider.getBlockNumber();
      this.state.head = head;

      if (this.rescanFrom !== null) {
        const from = this.rescanFrom;
        this.rescanFrom = null;
        this.state.lastScanned = from - 1;
        this.recentBlocks = this.recentBlocks.filter((b) => b.number < from);
        this.persist();
      }

      if (this.state.lastScanned === null) {
        this.state.lastScanned = Math.max(0, head - 1);
        this.persist();
      }

      await this.checkReorg();

      if (!this.addresses.loaded) {
        this.log.debug({ network: this.network }, 'watch addresses not loaded yet, skipping scan');
      } else {
        await this.scanForward(head);
      }

      // Пересчёт подтверждений строго один раз на новый head (SPEC §7.4).
      if (this.lastHeadProcessed !== head) {
        this.lastHeadProcessed = head;
        await this.tracker.onHead(head);
      }

      this.state.ok();
    } catch (err) {
      this.state.fail(err);
      this.log.error(
        { network: this.network, err: (err as Error).message, consecutiveErrors: this.state.consecutiveErrors },
        'evm scan tick failed',
      );
    }
  }

  /** Проверка parentHash последнего просканированного блока (SPEC §7.6). */
  private async checkReorg(): Promise<void> {
    const last = this.recentBlocks.at(-1);
    if (!last) return;

    const block = await this.provider.getBlock(last.number);
    if (block && block.hash && block.hash.toLowerCase() === last.hash.toLowerCase()) return;

    const rewindTo = Math.max(0, last.number - this.cfg.reorgDepth + 1);
    this.log.warn(
      { network: this.network, at: last.number, expected: last.hash, actual: block?.hash ?? null, rewindTo },
      'reorg detected, rewinding',
    );

    this.recentBlocks = this.recentBlocks.filter((b) => b.number < rewindTo);
    this.state.lastScanned = rewindTo - 1;
    this.persist();
    await this.tracker.revalidateFrom(rewindTo);
  }

  private async scanForward(head: number): Promise<void> {
    const from = (this.state.lastScanned ?? head - 1) + 1;
    if (from > head) return;

    const to = Math.min(head, from + this.batchBlocks - 1);
    const tokens = this.tokenByContract();
    if (tokens.size === 0) {
      this.state.lastScanned = to;
      this.persist();
      return;
    }

    const watched = this.addresses.values();
    if (watched.length > 0) {
      let logs: RawLog[];
      try {
        logs = await this.getLogsChunked(from, to, [...tokens.keys()], watched);
      } catch (err) {
        // Публичные RPC балансируются между нодами: head, полученный от одной ноды,
        // может быть ещё не известен другой. Это не ошибка — просто ждём следующий тик.
        if (BEYOND_HEAD_ERROR.test(err instanceof Error ? err.message : String(err))) {
          this.log.debug({ network: this.network, from, to, head }, 'rpc node behind head, retrying next tick');
          return;
        }
        if (this.shrinkOnRangeError(err)) return;
        throw err;
      }
      await this.handleLogs(logs, tokens, head);
    }

    await this.recordBlockHash(to);
    this.state.lastScanned = to;
    this.persist();

    if (this.batchBlocks < this.maxBatchBlocks) {
      this.batchBlocks = Math.min(this.maxBatchBlocks, this.batchBlocks * 2);
      this.log.info({ network: this.network, batchBlocks: this.batchBlocks }, 'evm batch size restored');
    }
  }

  private shrinkOnRangeError(err: unknown): boolean {
    const message = err instanceof Error ? err.message : String(err);
    if (!RANGE_ERROR.test(message) || this.batchBlocks <= 1) return false;
    this.batchBlocks = Math.max(1, Math.floor(this.batchBlocks / 2));
    this.state.fail(err);
    this.log.warn(
      { network: this.network, batchBlocks: this.batchBlocks, err: message },
      'rpc range error, shrinking evm batch',
    );
    return true;
  }

  private async getLogsChunked(
    fromBlock: number,
    toBlock: number,
    contracts: string[],
    watched: string[],
  ): Promise<RawLog[]> {
    const out: RawLog[] = [];
    for (let i = 0; i < watched.length; i += ADDRESS_CHUNK) {
      const chunk = watched.slice(i, i + ADDRESS_CHUNK);
      const toTopics = chunk.map(addressToTopic);
      const logs = (await this.provider.getLogs({
        fromBlock,
        toBlock,
        address: contracts,
        topics: [TRANSFER_TOPIC, null, toTopics],
      })) as unknown as RawLog[];
      out.push(...logs);
    }
    return out;
  }

  private async handleLogs(logs: RawLog[], tokens: Map<string, TokenConfig>, head: number): Promise<void> {
    const fresh: TransactionReport[] = [];

    for (const raw of logs) {
      const ev = parseTransferLog(raw);
      if (!ev) continue;

      const token = tokens.get(ev.contract.toLowerCase());
      if (!token) continue;
      if (!this.addresses.has(ev.to)) continue;
      if (ev.valueRaw === 0n) continue;

      const confirmations = this.tracker.confirmationsFor(ev.blockNumber, head);
      const report = buildEvmReport(this.network, ev, token, confirmations);
      if (this.tracker.add(report)) {
        fresh.push(report);
      }
    }

    if (fresh.length > 0) {
      this.log.info({ network: this.network, count: fresh.length }, 'detected incoming transfers');
      await this.report(fresh);
    }
  }

  private async recordBlockHash(blockNumber: number): Promise<void> {
    try {
      const block = await this.provider.getBlock(blockNumber);
      if (!block?.hash) return;
      this.recentBlocks.push({ number: blockNumber, hash: block.hash });
      if (this.recentBlocks.length > this.cfg.reorgDepth) {
        this.recentBlocks = this.recentBlocks.slice(-this.cfg.reorgDepth);
      }
    } catch (err) {
      this.log.warn(
        { network: this.network, blockNumber, err: (err as Error).message },
        'cannot record block hash for reorg protection',
      );
    }
  }

  health(): NetworkHealth {
    return healthPayload(this.network, this.netConfig.is_enabled, this.state, this.tracker.size);
  }

  pendingCount(): number {
    return this.tracker.size;
  }

  headBlock(): number | null {
    return this.state.head;
  }

  lastScannedBlock(): number | null {
    return this.state.lastScanned;
  }

  healthy(): boolean {
    return this.state.healthy;
  }

  lastError(): string | null {
    return this.state.errorMessage;
  }

  async close(): Promise<void> {
    this.persist();
    this.provider.destroy();
  }
}
