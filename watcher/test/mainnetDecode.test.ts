import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { Interface, formatUnits, getAddress } from 'ethers';
import { buildEvmReport, parseTransferLog, type RawLog } from '../src/scanner/evmDecode.js';
import { buildTronReport, parseTronBlock, type TronBlock } from '../src/scanner/tronDecode.js';
import { tronBase58ToHex, tronHexToBase58 } from '../src/tronAddress.js';
import type { TokenConfig } from '../src/types.js';

/**
 * Регрессия на РЕАЛЬНЫХ данных mainnet (снапшоты в test/fixtures, снятые с
 * ethereum-rpc.publicnode.com / bsc-rpc.publicnode.com / api.trongrid.io).
 *
 * Смысл: декодер обязан совпадать с каноничной интерпретацией цепочки
 * (ABI-декодер ethers для EVM, событие Transfer из receipt'ов для Tron),
 * иначе мы либо теряем депозит, либо зачисляем не ту сумму.
 */

const here = path.dirname(fileURLToPath(import.meta.url));
const fixture = <T>(name: string): T => JSON.parse(readFileSync(path.join(here, 'fixtures', name), 'utf8')) as T;

interface JsonRpcLog {
  address: string;
  topics: string[];
  data: string;
  blockNumber: string;
  blockHash: string;
  transactionHash: string;
  logIndex: string;
}

const TRANSFER_IFACE = new Interface(['event Transfer(address indexed from, address indexed to, uint256 value)']);

function toRawLog(log: JsonRpcLog): RawLog {
  return { ...log, blockNumber: Number.parseInt(log.blockNumber, 16), logIndex: Number.parseInt(log.logIndex, 16) };
}

describe('EVM: реальные Transfer-логи mainnet', () => {
  const cases: { file: string; network: 'ethereum' | 'bsc'; token: TokenConfig }[] = [
    {
      file: 'mainnet_eth_usdt_logs.json',
      network: 'ethereum',
      token: { symbol: 'USDT', contract_address: '0xdAC17F958D2ee523a2206206994597C13D831ec7', decimals: 6 },
    },
    {
      file: 'mainnet_bsc_usdt_logs.json',
      network: 'bsc',
      token: { symbol: 'USDT', contract_address: '0x55d398326f99059fF775485246999027B3197955', decimals: 18 },
    },
  ];

  for (const { file, network, token } of cases) {
    it(`${network} USDT: from/to/amount совпадают с ABI-декодером ethers`, () => {
      const logs = fixture<JsonRpcLog[]>(file);
      expect(logs.length).toBeGreaterThanOrEqual(3);

      for (const log of logs) {
        const ev = parseTransferLog(toRawLog(log));
        expect(ev, `лог ${log.transactionHash}#${log.logIndex} должен разобраться`).not.toBeNull();

        const reference = TRANSFER_IFACE.parseLog({ topics: log.topics, data: log.data });
        expect(ev!.from).toBe(getAddress(reference!.args[0] as string));
        expect(ev!.to).toBe(getAddress(reference!.args[1] as string));
        expect(ev!.valueRaw).toBe(reference!.args[2] as bigint);
        expect(ev!.contract).toBe(getAddress(log.address));

        const report = buildEvmReport(network, ev!, token, 1);
        expect(report.amount_raw).toBe((reference!.args[2] as bigint).toString());
        // amount == amount_raw / 10^decimals, как это показывает эксплорер
        expect(Number(report.amount)).toBeCloseTo(Number(formatUnits(reference!.args[2] as bigint, token.decimals)), 12);
        expect(report.log_index).toBe(Number.parseInt(log.logIndex, 16));
        expect(report.block_number).toBe(Number.parseInt(log.blockNumber, 16));
      }
    });
  }

  it('ethereum: 6 знаков — amount равен amount_raw / 1e6 без потери точности', () => {
    const logs = fixture<JsonRpcLog[]>('mainnet_eth_usdt_logs.json');
    const token: TokenConfig = {
      symbol: 'USDT',
      contract_address: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
      decimals: 6,
    };
    for (const log of logs) {
      const ev = parseTransferLog(toRawLog(log))!;
      const report = buildEvmReport('ethereum', ev, token, 1);
      const [whole, frac = ''] = report.amount.split('.');
      const restored = BigInt(whole) * 1_000_000n + BigInt(frac.padEnd(6, '0') || '0');
      expect(restored.toString()).toBe(report.amount_raw);
    }
  });

  it('bsc: 18 знаков — amount восстанавливается в amount_raw без потери точности', () => {
    const logs = fixture<JsonRpcLog[]>('mainnet_bsc_usdt_logs.json');
    const token: TokenConfig = {
      symbol: 'USDT',
      contract_address: '0x55d398326f99059fF775485246999027B3197955',
      decimals: 18,
    };
    for (const log of logs) {
      const ev = parseTransferLog(toRawLog(log))!;
      const report = buildEvmReport('bsc', ev, token, 1);
      const [whole, frac = ''] = report.amount.split('.');
      const restored = BigInt(whole) * 10n ** 18n + BigInt(frac.padEnd(18, '0') || '0');
      expect(restored.toString()).toBe(report.amount_raw);
    }
  });
});

/** Независимая (без ethers) реализация base58check для перекрёстной проверки. */
const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
function base58check(hexPayload: string): string {
  const payload = Buffer.from(hexPayload, 'hex');
  const digest = createHash('sha256').update(createHash('sha256').update(payload).digest()).digest();
  const bytes = Buffer.concat([payload, digest.subarray(0, 4)]);
  let n = 0n;
  for (const b of bytes) n = n * 256n + BigInt(b);
  let out = '';
  while (n > 0n) {
    out = B58[Number(n % 58n)] + out;
    n /= 58n;
  }
  for (const b of bytes) {
    if (b !== 0) break;
    out = `1${out}`;
  }
  return out;
}

interface TronEventRow {
  tx: string;
  contract: string;
  from: string;
  to: string;
  value: string;
}

const TRON_USDT: TokenConfig = {
  symbol: 'USDT',
  contract_address: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
  decimals: 6,
};
const TRON_USDC: TokenConfig = {
  symbol: 'USDC',
  contract_address: 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8',
  decimals: 6,
};
const TRON_CONTRACTS = new Map<string, TokenConfig>([
  ['41a614f803b6fd780986a42c78ec9c7f77e6ded13c', TRON_USDT],
  ['413487b63d30b5b2c87fb7ffa8bcfade38eaac1abe', TRON_USDC],
]);

describe('Tron: реальный блок mainnet 85953090', () => {
  const block = fixture<TronBlock>('mainnet_tron_block.json');
  /** Событие Transfer из wallet/gettransactioninfobyblocknum — то, что видит Tronscan. */
  const events = fixture<TronEventRow[]>('mainnet_tron_block_events.json');

  it('base58check из tronHexToBase58 совпадает с независимой реализацией', () => {
    expect(base58check('41a614f803b6fd780986a42c78ec9c7f77e6ded13c')).toBe(TRON_USDT.contract_address);
    expect(base58check('413487b63d30b5b2c87fb7ffa8bcfade38eaac1abe')).toBe(TRON_USDC.contract_address);
  });

  it('адреса с ведущими нулевыми байтами конвертируются в base58 без потери длины', () => {
    // Ведущие нули внутри 20-байтового тела адреса — классическая ловушка
    // base58 (кодирование через BigInt их «съедает»). Здесь их защищает
    // ненулевой префикс 0x41, но проверить это надо явно.
    const cases = [
      `41${'00'.repeat(19)}01`,
      `41${'00'.repeat(3)}${'aa'.repeat(17)}`,
      `4100${'ff'.repeat(19)}`,
      `41${'00'.repeat(20)}`,
    ];
    for (const hex of cases) {
      const address = tronHexToBase58(hex);
      expect(address).toBe(base58check(hex));
      expect(address.startsWith('T')).toBe(true);
      expect(address).toHaveLength(34);
      // round-trip обязан вернуть ровно тот же hex, включая нули
      expect(tronBase58ToHex(address)).toBe(hex);
    }
  });

  it('каждый разобранный перевод совпадает с событием Transfer в receipt блока', () => {
    const transfers = parseTronBlock(block, TRON_CONTRACTS);
    expect(transfers.length).toBeGreaterThanOrEqual(3);

    const key = (r: { tx: string; contract: string; from: string; to: string; value: string }): string =>
      `${r.tx}|${r.contract}|${r.from}|${r.to}|${r.value}`;
    const onChain = new Set(events.map(key));

    for (const t of transfers) {
      const row = {
        tx: t.txHash,
        contract: t.contractHex,
        from: t.fromHex,
        to: t.toHex,
        value: t.valueRaw.toString(),
      };
      // Ни одного «лишнего» перевода: всё, что мы репортим, реально произошло в цепочке.
      expect(onChain.has(key(row)), `перевод ${t.txHash} не подтверждён событием Transfer`).toBe(true);
      // base58 и суммы — против независимых реализаций.
      expect(t.to).toBe(base58check(t.toHex));
      expect(t.from).toBe(base58check(t.fromHex));
      const token = TRON_CONTRACTS.get(t.contractHex)!;
      expect(buildTronReport(t, token, 1).amount_raw).toBe(t.valueRaw.toString());
    }
  });

  it('REGRESSION: адреса, закодированные 21 байтом (0x41 внутри слова), не теряются', () => {
    // Реальные кошельки кодируют аргумент `address` двумя способами: 12 нулевых
    // байт + 20 байт (EVM-стиль) и 11 нулевых байт + 0x41 + 20 байт (Tron-стиль).
    // TVM обрезает слово до младших 20 байт, поэтому обе формы — один и тот же
    // перевод. Раньше вторая форма молча отбрасывалась => терялся каждый третий
    // реальный TRC-20 перевод.
    const words = (block.transactions ?? [])
      .map((tx) => tx.raw_data?.contract?.[0]?.parameter?.value?.data ?? '')
      .filter((d) => d.toLowerCase().startsWith('a9059cbb') && d.length === 136)
      .map((d) => d.slice(8, 72).toLowerCase());
    const tronStyle = words.filter((w) => /^0{22}41/.test(w));
    expect(tronStyle.length, 'фикстура должна содержать Tron-стиль кодирования').toBeGreaterThan(0);

    const transfers = parseTronBlock(block, TRON_CONTRACTS);
    const decodedTronStyle = transfers.filter((t) =>
      tronStyle.some((w) => w.endsWith(t.toHex.slice(2))),
    );
    expect(decodedTronStyle.length).toBeGreaterThan(0);
  });

  it('покрывает все прямые вызовы токена: не разобранными остаются только переводы из чужих контрактов', () => {
    const transfers = parseTronBlock(block, TRON_CONTRACTS);
    const decoded = new Set(
      transfers.map((t) => `${t.txHash}|${t.contractHex}|${t.fromHex}|${t.toHex}|${t.valueRaw.toString()}`),
    );
    const txsInBlock = new Set((block.transactions ?? []).map((t) => t.txID));
    const missed = events.filter((e) => !decoded.has(`${e.tx}|${e.contract}|${e.from}|${e.to}|${e.value}`));

    // Всё пропущенное обязано быть переводом, инициированным НЕ прямым вызовом
    // токена (внутренняя транзакция из стороннего контракта) — это известное
    // ограничение сканирования по calldata (SPEC §7.3), а не ошибка декодера.
    for (const m of missed) {
      expect(txsInBlock.has(m.tx), `прямой вызов токена ${m.tx} не должен теряться`).toBe(false);
    }
    expect(missed.length / events.length).toBeLessThan(0.1);
  });
});
