import { afterEach, describe, expect, it, vi } from 'vitest';
import { BackendClient, BackendError } from '../src/backend/client.js';
import type { AppConfig } from '../src/config.js';

const cfg = {
  backendUrl: 'http://backend.invalid',
  internalToken: 'test-token-0123456789',
  backendRetries: 0,
  requestTimeoutMs: 1000,
} as unknown as AppConfig;

const silentLog = { warn() {}, info() {}, error() {}, debug() {}, child: () => silentLog } as never;

function respond(body: unknown, status = 200): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(typeof body === 'string' ? body : JSON.stringify(body), { status })),
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('BackendClient.getWatchAddresses', () => {
  const client = new BackendClient(cfg, silentLog);

  it('возвращает адреса из корректного ответа', async () => {
    respond({ addresses: [{ id: '1', address: '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266' }] });
    await expect(client.getWatchAddresses('ethereum')).resolves.toHaveLength(1);
  });

  it('пустой список принимается как явный (все адреса деактивированы)', async () => {
    respond({ addresses: [] });
    await expect(client.getWatchAddresses('ethereum')).resolves.toEqual([]);
  });

  it('REGRESSION: битое тело — ошибка, а не «нет адресов»', async () => {
    // Раньше такой ответ возвращал [] и стирал весь набор отслеживаемых адресов:
    // сканер продолжал двигать курсор по блокам и терял депозиты безвозвратно.
    for (const body of [{}, { addresses: null }, { addresses: 'nope' }, '<html>502</html>', 'null']) {
      respond(body);
      await expect(client.getWatchAddresses('tron')).rejects.toBeInstanceOf(BackendError);
    }
  });
});
