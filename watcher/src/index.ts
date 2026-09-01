import { loadConfig } from './config.js';
import { logger } from './logger.js';
import { Deriver } from './derivation.js';
import { BackendClient } from './backend/client.js';
import { StateStore } from './state/store.js';
import { ScannerManager } from './scanner/manager.js';
import { buildServer } from './http/server.js';

function buildDeriver(evmXpub: string, tronXpub: string): Deriver {
  const accepted: { evm?: string; tron?: string } = {};

  for (const [family, xpub] of [
    ['evm', evmXpub],
    ['tron', tronXpub],
  ] as const) {
    if (!xpub) {
      logger.warn({ family }, `${family.toUpperCase()}_XPUB is not set — derivation for this family is unavailable`);
      continue;
    }
    try {
      // Пробная деривация: некорректный xpub должен вскрыться на старте, а не в рантайме.
      new Deriver({ [family]: xpub }).derive(family === 'tron' ? 'tron' : 'ethereum', 0);
      accepted[family] = xpub;
    } catch (err) {
      logger.error({ family, err: (err as Error).message }, 'invalid xpub, derivation disabled for this family');
    }
  }

  return new Deriver(accepted);
}

async function main(): Promise<void> {
  const cfg = loadConfig();

  logger.info(
    {
      port: cfg.port,
      backendUrl: cfg.backendUrl,
      dataDir: cfg.dataDir,
      watcherEnabled: cfg.watcherEnabled,
      evmBatchBlocks: cfg.evmBatchBlocks,
      pollIntervalMs: cfg.pollIntervalMs,
    },
    'watcher starting',
  );

  if (!cfg.internalToken) {
    logger.error('INTERNAL_API_TOKEN is empty — authenticated endpoints will reject every request');
  }

  const store = new StateStore(cfg.stateFile, logger);
  await store.load();

  const deriver = buildDeriver(cfg.evmXpub, cfg.tronXpub);
  if (!deriver.ready) {
    logger.warn('no usable xpub configured: /addresses/* will return 503 xpub_missing until EVM_XPUB/TRON_XPUB are set');
  }

  const backend = new BackendClient(cfg, logger.child({ component: 'backend' }));

  let manager: ScannerManager | null = null;
  if (cfg.watcherEnabled) {
    manager = new ScannerManager(cfg, backend, store, logger.child({ component: 'scanner' }));
  } else {
    logger.warn('WATCHER_ENABLED=false — сканирование отключено, работает только деривация и /health');
  }

  const deps = { cfg, log: logger, deriver, manager, store };
  const app = buildServer(deps);

  await app.listen({ port: cfg.port, host: cfg.host });
  logger.info({ port: cfg.port, host: cfg.host }, 'http server listening');

  // Запуск сканеров не блокирует HTTP: backend может подниматься дольше.
  if (manager) {
    void manager.start().catch((err: unknown) => {
      logger.error({ err: (err as Error).message }, 'scanner manager failed to start');
    });
  }

  let shuttingDown = false;
  const shutdown = async (signal: string): Promise<void> => {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info({ signal }, 'shutting down');
    const timeout = setTimeout(() => {
      logger.error('graceful shutdown timed out, forcing exit');
      process.exit(1);
    }, 15_000);
    timeout.unref();

    try {
      await app.close();
      if (manager) await manager.stop();
      await store.close();
      logger.info('shutdown complete');
      clearTimeout(timeout);
      process.exit(0);
    } catch (err) {
      logger.error({ err: (err as Error).message }, 'error during shutdown');
      process.exit(1);
    }
  };

  process.on('SIGTERM', () => void shutdown('SIGTERM'));
  process.on('SIGINT', () => void shutdown('SIGINT'));

  process.on('unhandledRejection', (reason) => {
    logger.error({ reason: reason instanceof Error ? reason.message : String(reason) }, 'unhandled rejection');
  });
  process.on('uncaughtException', (err) => {
    logger.error({ err: err.message, stack: err.stack }, 'uncaught exception');
  });
}

main().catch((err: unknown) => {
  logger.fatal({ err: (err as Error).message, stack: (err as Error).stack }, 'watcher failed to start');
  process.exit(1);
});
