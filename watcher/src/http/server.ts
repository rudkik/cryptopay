import { timingSafeEqual } from 'node:crypto';
import Fastify, { LogController, type FastifyReply, type FastifyRequest } from 'fastify';
import type { AppConfig } from '../config.js';
import type { Logger } from '../logger.js';
import { Deriver, XpubMissingError } from '../derivation.js';
import type { ScannerManager } from '../scanner/manager.js';
import { RescanError } from '../scanner/manager.js';
import type { StateStore } from '../state/store.js';
import { isNetworkCode, type NetworkCode } from '../types.js';
import { redactSecrets } from '../redact.js';

/** Максимум адресов за один /addresses/derive-batch. */
const MAX_DERIVE_BATCH = 100;
/**
 * Тела запросов здесь — три коротких JSON-объекта. 32 КБ с запасом хватает и
 * не даёт превратить внутренний порт в мусорную корзину.
 */
const BODY_LIMIT_BYTES = 32 * 1024;

export interface ServerDeps {
  cfg: AppConfig;
  log: Logger;
  deriver: Deriver;
  manager: ScannerManager | null;
  store: StateStore;
}

type ErrorCode =
  | 'unauthenticated'
  | 'validation_error'
  | 'not_found'
  | 'invalid_state'
  | 'xpub_missing'
  | 'server_error';

function fail(
  reply: FastifyReply,
  status: number,
  code: ErrorCode,
  message: string,
  details?: Record<string, string[]>,
): FastifyReply {
  return reply.code(status).send({ error: { code, message, ...(details ? { details } : {}) } });
}

/** Сравнение токена за постоянное время. */
function tokenMatches(expected: string, provided: unknown): boolean {
  if (typeof provided !== 'string' || expected === '') return false;
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(provided, 'utf8');
  if (a.length !== b.length) {
    // Всё равно выполняем сравнение, чтобы не сливать длину по таймингу.
    timingSafeEqual(a, a);
    return false;
  }
  return timingSafeEqual(a, b);
}

export function buildServer(deps: ServerDeps) {
  const { cfg, log, deriver, store } = deps;

  // Логи запросов выключены: /health опрашивается docker healthcheck'ом каждые 30с.
  class QuietLogController extends LogController {
    constructor() {
      super({ disableRequestLogging: true });
    }
  }

  const app = Fastify({
    loggerInstance: log,
    logController: new QuietLogController(),
    bodyLimit: BODY_LIMIT_BYTES,
    // Сервис слушает только внутреннюю docker-сеть и не принимает решений по
    // IP клиента; доверять X-Forwarded-* незачем.
    trustProxy: false,
  });

  const requireAuth = async (req: FastifyRequest, reply: FastifyReply): Promise<void> => {
    if (!tokenMatches(cfg.internalToken, req.headers['x-internal-token'])) {
      await fail(reply, 401, 'unauthenticated', 'Invalid or missing X-Internal-Token header.');
    }
  };

  app.setNotFoundHandler((_req, reply) => {
    void fail(reply, 404, 'not_found', 'Endpoint not found.');
  });

  app.setErrorHandler((error: unknown, _req, reply) => {
    const err = error as { statusCode?: number; message?: string; stack?: string };
    if (err.statusCode === 400) {
      void fail(reply, 422, 'validation_error', err.message ?? 'Malformed request body.');
      return;
    }
    log.error({ err: err.message, stack: err.stack }, 'unhandled http error');
    void fail(reply, 500, 'server_error', 'Internal server error.');
  });

  // --- GET /health (без auth, SPEC §6.6) ---
  app.get('/health', async () => {
    // Отдаётся БЕЗ авторизации (SPEC §6.6): ни xpub, ни RPC-url, ни токен сюда
    // попасть не должны. Булевы флаги derivation.* — максимум, что раскрываем.
    // lastError маскируется дважды: в ScannerHealthState.fail и здесь.
    const networks = (deps.manager?.healthSnapshot() ?? []).map((n) => ({
      ...n,
      lastError: n.lastError === null ? null : redactSecrets(n.lastError).slice(0, 500),
    }));
    return {
      ok: true,
      watcherEnabled: cfg.watcherEnabled,
      derivationReady: deriver.ready,
      derivation: { evm: deriver.has('evm'), tron: deriver.has('tron') },
      statePersistent: store.persistent,
      networks,
    };
  });

  // --- POST /addresses/derive ---
  app.post('/addresses/derive', { preHandler: requireAuth }, async (req, reply) => {
    const body = (req.body ?? {}) as { network?: unknown; index?: unknown };

    const network = parseNetwork(body.network);
    if (!network) {
      return fail(reply, 422, 'validation_error', 'Invalid payload.', {
        network: ['network must be one of: ethereum, bsc, tron'],
      });
    }
    const index = parseIndex(body.index);
    if (index === null) {
      return fail(reply, 422, 'validation_error', 'Invalid payload.', {
        index: ['index must be a non-negative integer'],
      });
    }

    try {
      return deriver.derive(network, index);
    } catch (err) {
      return derivationError(reply, err);
    }
  });

  // --- POST /addresses/derive-batch ---
  app.post('/addresses/derive-batch', { preHandler: requireAuth }, async (req, reply) => {
    const body = (req.body ?? {}) as { network?: unknown; from?: unknown; count?: unknown };

    const network = parseNetwork(body.network);
    if (!network) {
      return fail(reply, 422, 'validation_error', 'Invalid payload.', {
        network: ['network must be one of: ethereum, bsc, tron'],
      });
    }
    const from = parseIndex(body.from ?? 0);
    if (from === null) {
      return fail(reply, 422, 'validation_error', 'Invalid payload.', {
        from: ['from must be a non-negative integer'],
      });
    }
    const count = parseIndex(body.count);
    if (count === null || count < 1 || count > MAX_DERIVE_BATCH) {
      return fail(reply, 422, 'validation_error', 'Invalid payload.', {
        count: [`count must be an integer between 1 and ${MAX_DERIVE_BATCH}`],
      });
    }

    try {
      return { addresses: deriver.deriveBatch(network, from, count) };
    } catch (err) {
      return derivationError(reply, err);
    }
  });

  // --- POST /rescan ---
  app.post('/rescan', { preHandler: requireAuth }, async (req, reply) => {
    const body = (req.body ?? {}) as { network?: unknown; from_block?: unknown; force?: unknown };

    const network = parseNetwork(body.network);
    if (!network) {
      return fail(reply, 422, 'validation_error', 'Invalid payload.', {
        network: ['network must be one of: ethereum, bsc, tron'],
      });
    }
    const fromBlock = parseIndex(body.from_block);
    if (fromBlock === null) {
      return fail(reply, 422, 'validation_error', 'Invalid payload.', {
        from_block: ['from_block must be a non-negative integer'],
      });
    }

    const force = body.force === true || body.force === 'true';

    if (!cfg.watcherEnabled || !deps.manager) {
      return fail(reply, 409, 'invalid_state', 'Scanning is disabled (WATCHER_ENABLED=false).');
    }

    try {
      deps.manager.rescan(network, fromBlock, force);
    } catch (err) {
      if (err instanceof RescanError) {
        return err.status === 422
          ? fail(reply, 422, 'validation_error', err.message)
          : fail(reply, 409, 'invalid_state', err.message);
      }
      throw err;
    }

    return { ok: true, network, from_block: fromBlock, force };
  });

  function derivationError(reply: FastifyReply, err: unknown): FastifyReply {
    if (err instanceof XpubMissingError) {
      return fail(
        reply,
        503,
        'xpub_missing',
        'Derivation is not configured: set EVM_XPUB / TRON_XPUB (see `npm run keygen`).',
      );
    }
    log.error({ err: (err as Error).message }, 'derivation failed');
    return fail(reply, 500, 'server_error', 'Derivation failed.');
  }

  return app;
}

function parseNetwork(value: unknown): NetworkCode | null {
  if (typeof value !== 'string') return null;
  const normalized = value.trim().toLowerCase();
  return isNetworkCode(normalized) ? normalized : null;
}

function parseIndex(value: unknown): number | null {
  const n = typeof value === 'string' ? Number(value) : value;
  if (typeof n !== 'number' || !Number.isInteger(n) || n < 0 || n > 0x7fffffff) return null;
  return n;
}
