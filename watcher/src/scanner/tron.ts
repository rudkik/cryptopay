import type { AppConfig } from '../config.js';
import type { Logger } from '../logger.js';
import type { BackendClient } from '../backend/client.js';
import type { StateStore } from '../state/store.js';
import type { NetworkConfig, NetworkHealth, TokenConfig, TransactionReport } from '../types.js';
import { tronBase58ToHex } from '../tronAddress.js';
import { ScannerHealthState, healthPayload, type Scanner } from './base.js';
import { ConfirmationTracker, type PendingTx, type VerifyResult } from './tracker.js';
import { blockNumberOf, buildTronReport, parseTronBlock } from './tronDecode.js';
import { TRON_MAX_BLOCKS_PER_CALL, TronClient } from './tronClient.js';
import type { WatchAddressSet } from './addresses.js';

export class TronScanner implements Scanner {
  readonly network = 'tron' as const;
  private netConfig: NetworkConfig;
  private readonly state: ScannerHealthState;
  private readonly tracker: ConfirmationTracker;
  private lastHeadProcessed: number | null = null;
  private rescanFrom: number | null = null;
  private solidityHead: number | null = null;

  constructor(
    netConfig: NetworkConfig,
    private readonly client: TronClient,
    cfg: AppConfig,
    private readonly backend: BackendClient,
    private readonly store: StateStore,
    private readonly addresses: WatchAddressSet,
    private readonly log: Logger,
  ) {
    this.netConfig = netConfig;
    this.state = new ScannerHealthState(cfg.maxLagBlocks, cfg.maxConsecutiveErrors);

    this.tracker = new ConfirmationTracker({
      network: 'tron',
      confirmationsRequired: netConfig.confirmations_required,
      verify: (tx) => this.verify(tx),
      report: (txs) => this.report(txs),
      onChange: () => this.persist(),
    });

    const persisted = this.store.get('tron');
    this.tracker.restore(persisted.pending);
    this.state.lastScanned = persisted.lastScannedBlock;
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

  /** hex-адрес контракта (41…) -> конфиг токена. */
  private contractIndex(): Map<string, TokenConfig> {
    const map = new Map<string, TokenConfig>();
    for (const token of this.netConfig.tokens ?? []) {
      if (!token?.contract_address) continue;
      try {
        map.set(tronBase58ToHex(token.contract_address).toLowerCase(), token);
      } catch {
        this.log.warn({ contract: token.contract_address }, 'invalid tron token contract, skipped');
      }
    }
    return map;
  }

  private persist(): void {
    this.store.set('tron', {
      lastScannedBlock: this.state.lastScanned,
      recentBlocks: [],
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
      this.log.error({ network: 'tron', count: txs.length, err: (err as Error).message }, 'failed to report transactions');
    }
  }

  /** Подтверждение: блок ≤ solidity head и receipt.result === 'SUCCESS' (SPEC §7.4). */
  private async verify(tx: PendingTx): Promise<VerifyResult> {
    const solid = this.solidityHead ?? (await this.refreshSolidityHead());
    if (solid === null || tx.block_number > solid) return 'unknown';

    let info: Awaited<ReturnType<TronClient['getTransactionInfoById']>>;
    try {
      info = await this.client.getTransactionInfoById(tx.tx_hash);
    } catch (err) {
      this.log.warn(
        { txHash: tx.tx_hash, err: (err as Error).message },
        'gettransactioninfobyid failed, confirmation deferred',
      );
      throw err;
    }
    if (!info) return 'orphaned';

    const result = info.receipt?.result;
    if (result === 'SUCCESS') return 'confirmed';
    if (result === undefined) return 'unknown';
    return 'orphaned';
  }

  private async refreshSolidityHead(): Promise<number | null> {
    try {
      const block = await this.client.getSolidityNowBlock();
      this.solidityHead = blockNumberOf(block);
    } catch (err) {
      this.log.warn({ err: (err as Error).message }, 'cannot fetch tron solidity head');
    }
    return this.solidityHead;
  }

  requestRescan(fromBlock: number): void {
    this.rescanFrom = Math.max(0, Math.floor(fromBlock));
    this.log.info({ network: 'tron', fromBlock: this.rescanFrom }, 'rescan requested');
  }

  async tick(): Promise<void> {
    try {
      const nowBlock = await this.client.getNowBlock();
      const head = blockNumberOf(nowBlock);
      if (head === null) throw new Error('tron getnowblock returned no block number');
      this.state.head = head;
      await this.refreshSolidityHead();

      if (this.rescanFrom !== null) {
        this.state.lastScanned = this.rescanFrom - 1;
        this.rescanFrom = null;
        this.persist();
      }

      if (this.state.lastScanned === null) {
        this.state.lastScanned = Math.max(0, head - 1);
        this.persist();
      }

      if (!this.addresses.loaded) {
        this.log.debug({ network: 'tron' }, 'watch addresses not loaded yet, skipping scan');
      } else {
        await this.scanForward(head);
      }

      if (this.lastHeadProcessed !== head) {
        this.lastHeadProcessed = head;
        await this.tracker.onHead(head);
      }

      this.state.ok();
    } catch (err) {
      this.state.fail(err);
      this.log.error(
        { network: 'tron', err: (err as Error).message, consecutiveErrors: this.state.consecutiveErrors },
        'tron scan tick failed',
      );
    }
  }

  private async scanForward(head: number): Promise<void> {
    const from = (this.state.lastScanned ?? head - 1) + 1;
    if (from > head) return;

    const contracts = this.contractIndex();
    if (contracts.size === 0 || this.addresses.size === 0) {
      this.state.lastScanned = head;
      this.persist();
      return;
    }

    const endExclusive = Math.min(head + 1, from + TRON_MAX_BLOCKS_PER_CALL);
    const blocks = await this.client.getBlockByLimit(from, endExclusive);

    const fresh: TransactionReport[] = [];
    for (const block of blocks) {
      for (const transfer of parseTronBlock(block, contracts)) {
        if (!this.addresses.has(transfer.to)) continue;
        if (transfer.valueRaw === 0n) continue;
        const token = contracts.get(transfer.contractHex);
        if (!token) continue;

        const confirmations = this.tracker.confirmationsFor(transfer.blockNumber, head);
        const report = buildTronReport(transfer, token, confirmations);
        if (this.tracker.add(report)) fresh.push(report);
      }
    }

    if (fresh.length > 0) {
      this.log.info({ network: 'tron', count: fresh.length }, 'detected incoming transfers');
      await this.report(fresh);
    }

    this.state.lastScanned = endExclusive - 1;
    this.persist();
  }

  health(): NetworkHealth {
    return healthPayload('tron', this.netConfig.is_enabled, this.state, this.tracker.size);
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
  }
}
