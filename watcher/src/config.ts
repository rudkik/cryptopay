import 'dotenv/config';
import path from 'node:path';
import { registerSecret } from './redact.js';

function str(name: string, fallback = ''): string {
  const v = process.env[name];
  return v === undefined || v === '' ? fallback : v;
}

function int(name: string, fallback: number): number {
  const raw = process.env[name];
  if (raw === undefined || raw.trim() === '') return fallback;
  const n = Number.parseInt(raw, 10);
  return Number.isFinite(n) && n > 0 ? n : fallback;
}

function bool(name: string, fallback: boolean): boolean {
  const raw = process.env[name];
  if (raw === undefined || raw.trim() === '') return fallback;
  return !['false', '0', 'no', 'off'].includes(raw.trim().toLowerCase());
}

/**
 * Значения INTERNAL_API_TOKEN, с которыми запускаться в проде запрещено.
 * Сравнение по префиксу: в репозитории плейсхолдер встречается в двух вариантах
 * (`change-me-internal-token` и `change-me-internal-token-please`).
 */
const PLACEHOLDER_TOKEN_PREFIX = 'change-me';

/** Окружения, в которых допустимы дефолтные секреты. */
const DEV_ENVS = new Set(['local', 'dev', 'development', 'test', 'testing']);

export interface AppConfig {
  /**
   * Окружение развёртывания. Берётся из APP_ENV (единый .env compose), с
   * откатом на NODE_ENV. Именно APP_ENV, а не NODE_ENV: NODE_ENV=production
   * зашит в Dockerfile ради `npm ci --omit=dev` и потому НЕ является признаком
   * боевого стенда.
   */
  appEnv: string;
  /** true => дефолтные/пустые секреты фатальны. */
  strictSecrets: boolean;
  port: number;
  host: string;
  backendUrl: string;
  internalToken: string;
  ethRpcUrl: string;
  bscRpcUrl: string;
  tronApiUrl: string;
  tronApiKey: string;
  evmXpub: string;
  tronXpub: string;
  watcherEnabled: boolean;
  dataDir: string;
  stateFile: string;
  logLevel: string;
  evmBatchBlocks: number;
  pollIntervalMs: number;
  configRefreshMs: number;
  addressRefreshMs: number;
  heartbeatMs: number;
  /** Максимум блок-хешей для reorg-защиты (SPEC §7.6). */
  reorgDepth: number;
  /** Лаг больше этого числа блоков => healthy=false (SPEC §7.5). */
  maxLagBlocks: number;
  /** Столько подряд идущих ошибок RPC => healthy=false (SPEC §7.5). */
  maxConsecutiveErrors: number;
  backendRetries: number;
  requestTimeoutMs: number;
}

export function loadConfig(): AppConfig {
  const dataDir = path.resolve(str('DATA_DIR', './data'));
  const appEnv = str('APP_ENV', str('NODE_ENV', 'production')).trim().toLowerCase();

  // Всё, что не должно всплыть в логах и в /health.
  registerSecret(str('INTERNAL_API_TOKEN'));
  registerSecret(str('TRON_API_KEY'));
  registerSecret(str('EVM_XPUB'));
  registerSecret(str('TRON_XPUB'));

  return {
    appEnv,
    strictSecrets: !DEV_ENVS.has(appEnv),
    port: int('PORT', 3100),
    host: str('HOST', '0.0.0.0'),
    backendUrl: str('BACKEND_URL', 'http://nginx').replace(/\/+$/, ''),
    internalToken: str('INTERNAL_API_TOKEN'),
    ethRpcUrl: str('ETH_RPC_URL'),
    bscRpcUrl: str('BSC_RPC_URL'),
    tronApiUrl: str('TRON_API_URL', 'https://api.trongrid.io').replace(/\/+$/, ''),
    tronApiKey: str('TRON_API_KEY'),
    evmXpub: str('EVM_XPUB'),
    tronXpub: str('TRON_XPUB'),
    watcherEnabled: bool('WATCHER_ENABLED', true),
    dataDir,
    stateFile: path.join(dataDir, 'state.json'),
    logLevel: str('LOG_LEVEL', 'info'),
    evmBatchBlocks: int('EVM_BATCH_BLOCKS', 20),
    pollIntervalMs: int('POLL_INTERVAL_MS', 5000),
    configRefreshMs: int('CONFIG_REFRESH_MS', 60_000),
    addressRefreshMs: int('ADDRESS_REFRESH_MS', 10_000),
    heartbeatMs: int('HEARTBEAT_MS', 15_000),
    reorgDepth: int('REORG_DEPTH', 64),
    maxLagBlocks: int('MAX_LAG_BLOCKS', 50),
    maxConsecutiveErrors: int('MAX_CONSECUTIVE_ERRORS', 3),
    backendRetries: int('BACKEND_RETRIES', 5),
    requestTimeoutMs: int('REQUEST_TIMEOUT_MS', 30_000),
  };
}

/** Токен пустой или остался плейсхолдером из .env.example. */
export function isPlaceholderToken(token: string): boolean {
  const t = token.trim().toLowerCase();
  return t === '' || t.startsWith(PLACEHOLDER_TOKEN_PREFIX);
}

export function rpcUrlFor(cfg: AppConfig, network: string): string {
  if (network === 'ethereum') return cfg.ethRpcUrl;
  if (network === 'bsc') return cfg.bscRpcUrl;
  return '';
}
