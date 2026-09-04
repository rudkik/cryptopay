import { HDNodeWallet } from 'ethers';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ACCOUNT_PATHS, Deriver, xpubsFromMnemonic } from '../src/derivation.js';
import { buildServer, type ServerDeps } from '../src/http/server.js';
import { clearSecrets, registerSecret } from '../src/redact.js';
import { StateStore } from '../src/state/store.js';
import type { AppConfig } from '../src/config.js';
import type { Logger } from '../src/logger.js';

const ENV_MNEMONIC = 'test test test test test test test test test test test junk';
const BODY_MNEMONIC = 'legal winner thank year wave sausage worth useful legal winner thank yellow';

const ENV_XPUBS = xpubsFromMnemonic(ENV_MNEMONIC);
const BODY_XPUBS = xpubsFromMnemonic(BODY_MNEMONIC);

function baseConfig(overrides: Partial<AppConfig> = {}): AppConfig {
  return {
    appEnv: 'test',
    strictSecrets: false,
    port: 3100,
    host: '0.0.0.0',
    backendUrl: 'http://nginx',
    internalToken: 'test-internal-token-0123456789',
    ethRpcUrl: '',
    bscRpcUrl: '',
    tronApiUrl: 'https://api.trongrid.io',
    tronApiKey: '',
    evmXpub: '',
    tronXpub: '',
    watcherEnabled: false,
    dataDir: './data',
    stateFile: './data/state.json',
    logLevel: 'silent',
    evmBatchBlocks: 20,
    pollIntervalMs: 5000,
    configRefreshMs: 60_000,
    addressRefreshMs: 10_000,
    heartbeatMs: 15_000,
    reorgDepth: 64,
    maxLagBlocks: 50,
    maxConsecutiveErrors: 3,
    backendRetries: 5,
    requestTimeoutMs: 30_000,
    ...overrides,
  };
}

/** Логгер-шпион: собирает всё, что было передано в любой из уровней логирования. */
function createSpyLogger(): { log: Logger; serialized: () => string } {
  const calls: unknown[] = [];
  const record = (...args: unknown[]): void => {
    calls.push(args);
  };
  const base: Record<string, unknown> = {
    fatal: vi.fn(record),
    error: vi.fn(record),
    warn: vi.fn(record),
    info: vi.fn(record),
    debug: vi.fn(record),
    trace: vi.fn(record),
    silent: vi.fn(),
    level: 'silent',
  };
  base.child = () => base;
  return {
    log: base as unknown as Logger,
    serialized: () => JSON.stringify(calls),
  };
}

function buildDeps(overrides: Partial<ServerDeps> = {}): { deps: ServerDeps; spy: ReturnType<typeof createSpyLogger> } {
  const spy = createSpyLogger();
  const cfg = baseConfig();
  const deps: ServerDeps = {
    cfg,
    log: spy.log,
    deriver: new Deriver({}),
    manager: null,
    store: { persistent: true } as unknown as StateStore,
    ...overrides,
  };
  return { deps, spy };
}

const AUTH_HEADERS = { 'x-internal-token': 'test-internal-token-0123456789', 'content-type': 'application/json' };

describe('конверты ошибок HTTP', () => {
  const routes: [string, string][] = [
    ['POST', '/health'],
    ['GET', '/addresses/derive'],
    ['GET', '/addresses/derive-batch'],
    ['DELETE', '/rescan'],
    ['GET', '/rescan'],
    ['GET', '/nope'],
  ];

  for (const [method, url] of routes) {
    it(`REGRESSION: ${method} ${url} -> 404 not_found даже с Content-Type: application/json`, async () => {
      // Раньше пустое тело при json content-type падало в парсере ДО маршрутизации,
      // и «неизвестный метод» отвечал 422 «Body cannot be empty» вместо 404.
      const { deps } = buildDeps();
      const app = buildServer(deps);
      const res = await app.inject({
        method: method as 'GET',
        url,
        headers: { 'content-type': 'application/json' },
      });
      expect(res.statusCode).toBe(404);
      expect(res.json()).toEqual({ error: { code: 'not_found', message: 'Endpoint not found.' } });
      await app.close();
    });
  }

  it('запрос без тела к существующему эндпоинту даёт validation_error с details', async () => {
    const { deps } = buildDeps();
    const app = buildServer(deps);
    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
    });
    expect(res.statusCode).toBe(422);
    expect(res.json().error.code).toBe('validation_error');
    expect(res.json().error.details).toHaveProperty('network');
    await app.close();
  });

  it('битый JSON -> 422 validation_error', async () => {
    const { deps } = buildDeps();
    const app = buildServer(deps);
    const res = await app.inject({ method: 'POST', url: '/rescan', headers: AUTH_HEADERS, payload: '{oops' });
    expect(res.statusCode).toBe(422);
    expect(res.json().error.code).toBe('validation_error');
    await app.close();
  });

  it('без токена -> 401 unauthenticated на всех защищённых маршрутах', async () => {
    const { deps } = buildDeps();
    const app = buildServer(deps);
    for (const url of ['/addresses/derive', '/addresses/derive-batch', '/rescan']) {
      const res = await app.inject({
        method: 'POST',
        url,
        headers: { 'content-type': 'application/json' },
        payload: JSON.stringify({ network: 'ethereum', index: 0, count: 1, from_block: 1 }),
      });
      expect(res.statusCode, url).toBe(401);
      expect(res.json().error.code).toBe('unauthenticated');
    }
    await app.close();
  });

  it('/health не требует авторизации и не отдаёт ни xpub, ни RPC-url, ни токен', async () => {
    const { deps } = buildDeps();
    const manager = {
      healthSnapshot: () => [
        {
          code: 'ethereum' as const,
          enabled: true,
          headBlock: 100,
          lastScannedBlock: 98,
          lag: 2,
          pendingTxs: 1,
          lastError:
            'could not detect network (requestUrl="https://eth.example/SECRETKEY?apikey=abc") ' +
            'token=test-internal-token-0123456789 ' +
            'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz',
          updatedAt: new Date().toISOString(),
        },
      ],
    };
    registerSecret('test-internal-token-0123456789');
    const app = buildServer({ ...deps, manager: manager as never });
    const res = await app.inject({ method: 'GET', url: '/health' });
    expect(res.statusCode).toBe(200);
    expect(res.body).not.toContain('SECRETKEY');
    expect(res.body).not.toContain('test-internal-token-0123456789');
    expect(res.body).not.toContain('xpub6CUGRUonZSQ4');
    expect(res.json().networks[0].lastError).toContain('[redacted-xpub]');
    await app.close();
  });
});

describe('POST /addresses/derive with body xpub', () => {
  beforeEach(() => clearSecrets());

  it('derives from the body xpub instead of the env xpub, and it differs from the env-derived address', async () => {
    const { deps } = buildDeps({ deriver: new Deriver({ evm: ENV_XPUBS.evmXpub, tron: ENV_XPUBS.tronXpub }) });
    const app = buildServer(deps);

    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', index: 0, xpub: BODY_XPUBS.evmXpub },
    });

    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.path).toBe("m/44'/60'/0'/0/0");

    const expected = new Deriver({ evm: BODY_XPUBS.evmXpub }).derive('ethereum', 0).address;
    const envAddress = new Deriver({ evm: ENV_XPUBS.evmXpub }).derive('ethereum', 0).address;
    expect(body.address).toBe(expected);
    expect(body.address).not.toBe(envAddress);
  });

  it('works when the env xpub for that family is not configured (no 503 xpub_missing)', async () => {
    const { deps } = buildDeps({ deriver: new Deriver({}) });
    const app = buildServer(deps);

    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
      payload: { network: 'tron', index: 3, xpub: BODY_XPUBS.tronXpub },
    });

    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.path).toBe("m/44'/195'/0'/0/3");
    expect(body.address).toBe(new Deriver({ tron: BODY_XPUBS.tronXpub }).derive('tron', 3).address);
  });

  it('falls back to the env xpub when no body xpub is supplied (unchanged behaviour)', async () => {
    const { deps } = buildDeps({ deriver: new Deriver({ evm: ENV_XPUBS.evmXpub }) });
    const app = buildServer(deps);

    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', index: 0 },
    });

    expect(res.statusCode).toBe(200);
    expect(res.json().address).toBe(new Deriver({ evm: ENV_XPUBS.evmXpub }).derive('ethereum', 0).address);
  });

  it('still returns 503 xpub_missing when no body xpub is supplied and env is unset', async () => {
    const { deps } = buildDeps({ deriver: new Deriver({}) });
    const app = buildServer(deps);

    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', index: 0 },
    });

    expect(res.statusCode).toBe(503);
    expect(res.json().error.code).toBe('xpub_missing');
  });
});

describe('POST /addresses/derive-batch with body xpub', () => {
  beforeEach(() => clearSecrets());

  it('returns count sequential addresses with correct paths, derived from the body xpub', async () => {
    const { deps } = buildDeps({ deriver: new Deriver({ evm: ENV_XPUBS.evmXpub }) });
    const app = buildServer(deps);

    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive-batch',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', from: 2, count: 4, xpub: BODY_XPUBS.evmXpub },
    });

    expect(res.statusCode).toBe(200);
    const { addresses } = res.json();
    expect(addresses).toHaveLength(4);
    expect(addresses.map((a: { index: number }) => a.index)).toEqual([2, 3, 4, 5]);
    expect(addresses.map((a: { path: string }) => a.path)).toEqual([
      "m/44'/60'/0'/0/2",
      "m/44'/60'/0'/0/3",
      "m/44'/60'/0'/0/4",
      "m/44'/60'/0'/0/5",
    ]);

    const expected = new Deriver({ evm: BODY_XPUBS.evmXpub }).deriveBatch('ethereum', 2, 4).map((d) => d.address);
    expect(addresses.map((a: { address: string }) => a.address)).toEqual(expected);
  });

  it('respects the count cap of 100', async () => {
    const { deps } = buildDeps({ deriver: new Deriver({ evm: ENV_XPUBS.evmXpub }) });
    const app = buildServer(deps);

    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive-batch',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', from: 0, count: 101, xpub: BODY_XPUBS.evmXpub },
    });

    expect(res.statusCode).toBe(422);
    expect(res.json().error.code).toBe('validation_error');
  });
});

describe('invalid body xpub -> 422 invalid_xpub', () => {
  beforeEach(() => clearSecrets());

  async function deriveWith(xpub: unknown): Promise<{ status: number; body: { error?: { code?: string } } }> {
    const { deps } = buildDeps({ deriver: new Deriver({ evm: ENV_XPUBS.evmXpub }) });
    const app = buildServer(deps);
    const res = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', index: 0, xpub },
    });
    return { status: res.statusCode, body: res.json() };
  }

  it('rejects a non-string xpub', async () => {
    const { status, body } = await deriveWith(123456);
    expect(status).toBe(422);
    expect(body.error?.code).toBe('invalid_xpub');
  });

  it('rejects an empty xpub', async () => {
    const { status, body } = await deriveWith('');
    expect(status).toBe(422);
    expect(body.error?.code).toBe('invalid_xpub');
  });

  it('rejects an xprv (private key) presented as xpub', async () => {
    const xprv = HDNodeWallet.fromPhrase(BODY_MNEMONIC, undefined, ACCOUNT_PATHS.evm).extendedKey;
    expect(xprv.startsWith('xprv')).toBe(true);
    const { status, body } = await deriveWith(xprv);
    expect(status).toBe(422);
    expect(body.error?.code).toBe('invalid_xpub');
  });

  it('rejects an xpub with a broken base58check checksum', async () => {
    const valid = BODY_XPUBS.evmXpub;
    const broken = `${valid.slice(0, -1)}${valid.at(-1) === 'a' ? 'b' : 'a'}`;
    const { status, body } = await deriveWith(broken);
    expect(status).toBe(422);
    expect(body.error?.code).toBe('invalid_xpub');
  });

  it('rejects a non-account-level xpub (depth != 3)', async () => {
    const deeper = HDNodeWallet.fromPhrase(BODY_MNEMONIC, undefined, ACCOUNT_PATHS.evm)
      .deriveChild(0)
      .neuter().extendedKey;
    const { status, body } = await deriveWith(deeper);
    expect(status).toBe(422);
    expect(body.error?.code).toBe('invalid_xpub');
    expect(body.error?.message).toBeDefined();
  });

  it('never echoes the supplied xpub value in the error response', async () => {
    const valid = BODY_XPUBS.evmXpub;
    const broken = `${valid.slice(0, -1)}${valid.at(-1) === 'a' ? 'b' : 'a'}`;
    const { body } = await deriveWith(broken);
    expect(JSON.stringify(body)).not.toContain(broken);
    expect(JSON.stringify(body)).not.toContain(valid.slice(0, -1));
  });
});

describe('xpub never reaches logs or responses', () => {
  beforeEach(() => clearSecrets());

  it('keeps the xpub out of logged output across valid and invalid requests', async () => {
    const { deps, spy } = buildDeps({ deriver: new Deriver({ evm: ENV_XPUBS.evmXpub }) });
    const app = buildServer(deps);

    const valid = BODY_XPUBS.evmXpub;
    const broken = `${valid.slice(0, -1)}${valid.at(-1) === 'a' ? 'b' : 'a'}`;

    const okRes = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', index: 0, xpub: valid },
    });
    const badRes = await app.inject({
      method: 'POST',
      url: '/addresses/derive',
      headers: AUTH_HEADERS,
      payload: { network: 'ethereum', index: 0, xpub: broken },
    });

    expect(okRes.statusCode).toBe(200);
    expect(badRes.statusCode).toBe(422);
    expect(JSON.stringify(okRes.json())).not.toContain(valid);
    expect(JSON.stringify(badRes.json())).not.toContain(broken);

    const logged = spy.serialized();
    expect(logged).not.toContain(valid);
    expect(logged).not.toContain(broken);
  });
});
