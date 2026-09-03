import { describe, expect, it } from 'vitest';
import {
  TRC20_TRANSFER_FROM_SELECTOR,
  decodeTrc20TransferData,
  isTronHash,
  parseTronBlock,
  type TronBlock,
} from '../src/scanner/tronDecode.js';
import type { TokenConfig } from '../src/types.js';

const USDT: TokenConfig = { symbol: 'USDT', contract_address: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', decimals: 6 };
const USDT_HEX = '41a614f803b6fd780986a42c78ec9c7f77e6ded13c';
const contracts = new Map<string, TokenConfig>([[USDT_HEX, USDT]]);

const TO_BASE58 = 'TWer2Ygk5TEheHp3TPuYeqxmB6SsGZmaL6';
const TO_HEX_BODY = 'e2e1a54926527fbb4e4420de4c6bab82beaee24d';
const FROM_HEX_BODY = '977c20977f412c2a1aa4ef3d49fe8f5f9a1f5b09';
const SPENDER_HEX = '41cccccccccccccccccccccccccccccccccccccccc';

const word = (body: string): string => `${'0'.repeat(24)}${body}`;
const amountWord = (v: bigint): string => v.toString(16).padStart(64, '0');

/** data = 23b872dd ‖ from(32) ‖ to(32) ‖ amount(32) — ровно 200 hex-символов. */
function transferFromData(from: string, to: string, amount: bigint): string {
  return `${TRC20_TRANSFER_FROM_SELECTOR}${word(from)}${word(to)}${amountWord(amount)}`;
}

function transferData(to: string, amount: bigint): string {
  return `a9059cbb${word(to)}${amountWord(amount)}`;
}

function makeBlock(data: string, number = 58_000_000): TronBlock {
  return {
    blockID: '0000000003757bd0fc8b1c1ab7b8c8f9b6f1d0c1f0a9e8d7c6b5a4938271605f',
    block_header: { raw_data: { number, timestamp: 1_735_000_000_000 } },
    transactions: [
      {
        ret: [{ contractRet: 'SUCCESS' }],
        txID: 'c1b2a3947f5e6d8c9b0a1f2e3d4c5b6a7988776655443322110099aabbccddee',
        raw_data: {
          contract: [
            {
              type: 'TriggerSmartContract',
              parameter: { value: { data, owner_address: SPENDER_HEX, contract_address: USDT_HEX } },
            },
          ],
        },
      },
    ],
  } as TronBlock;
}

describe('TRC-20 transferFrom decoding', () => {
  it('decodes transferFrom(address,address,uint256)', () => {
    const decoded = decodeTrc20TransferData(transferFromData(FROM_HEX_BODY, TO_HEX_BODY, 250_000_000n));
    expect(decoded).toEqual({
      method: 'transferFrom',
      toHex: `41${TO_HEX_BODY}`,
      fromHex: `41${FROM_HEX_BODY}`,
      valueRaw: 250_000_000n,
    });
  });

  it('accepts the 0x-prefixed and upper-case forms', () => {
    const data = transferFromData(FROM_HEX_BODY, TO_HEX_BODY, 1n);
    expect(decodeTrc20TransferData(`0x${data.toUpperCase()}`)?.valueRaw).toBe(1n);
  });

  it('reports the transferFrom sender as `from`, not msg.sender', () => {
    const transfers = parseTronBlock(makeBlock(transferFromData(FROM_HEX_BODY, TO_HEX_BODY, 7n)), contracts);
    expect(transfers).toHaveLength(1);
    expect(transfers[0]?.method).toBe('transferFrom');
    expect(transfers[0]?.to).toBe(TO_BASE58);
    expect(transfers[0]?.fromHex).toBe(`41${FROM_HEX_BODY}`);
    // owner_address (spender) сохраняется отдельно и не выдаётся за отправителя.
    expect(transfers[0]?.ownerHex).toBe(SPENDER_HEX);
    expect(transfers[0]?.valueRaw).toBe(7n);
  });

  it('still marks plain transfer as method=transfer with owner as sender', () => {
    const transfers = parseTronBlock(makeBlock(transferData(TO_HEX_BODY, 9n)), contracts);
    expect(transfers[0]?.method).toBe('transfer');
    expect(transfers[0]?.fromHex).toBe(SPENDER_HEX);
  });

  it('rejects transferFrom with a truncated or padded payload', () => {
    const good = transferFromData(FROM_HEX_BODY, TO_HEX_BODY, 5n);
    expect(decodeTrc20TransferData(good.slice(0, -2))).toBeNull();
    expect(decodeTrc20TransferData(`${good}00`)).toBeNull();
    expect(decodeTrc20TransferData(`${TRC20_TRANSFER_FROM_SELECTOR}dead`)).toBeNull();
  });

  it('rejects transfer whose payload carries a tail beyond the two words', () => {
    expect(decodeTrc20TransferData(`${transferData(TO_HEX_BODY, 5n)}00`)).toBeNull();
  });

  it('rejects address words with dirt in the top 12 bytes', () => {
    const dirty = `23b872dd${'1'.repeat(24)}${FROM_HEX_BODY}${word(TO_HEX_BODY)}${amountWord(1n)}`;
    expect(decodeTrc20TransferData(dirty)).toBeNull();
  });

  it('rejects non-hex and unknown selectors', () => {
    expect(decodeTrc20TransferData('zzzz')).toBeNull();
    expect(decodeTrc20TransferData(`095ea7b3${word(TO_HEX_BODY)}${amountWord(1n)}`)).toBeNull();
  });
});

describe('tron block/tx shape validation', () => {
  it('drops the whole block when blockID is not a 32-byte hash', () => {
    const block = makeBlock(transferData(TO_HEX_BODY, 5n));
    block.blockID = 'not-a-hash';
    expect(parseTronBlock(block, contracts)).toEqual([]);
  });

  it('drops transactions whose txID is not a 32-byte hash', () => {
    const block = makeBlock(transferData(TO_HEX_BODY, 5n));
    block.transactions![0]!.txID = 'abc';
    expect(parseTronBlock(block, contracts)).toEqual([]);
  });

  it('drops transactions carrying more than one contract', () => {
    const block = makeBlock(transferData(TO_HEX_BODY, 5n));
    const only = block.transactions![0]!.raw_data!.contract![0]!;
    block.transactions![0]!.raw_data!.contract = [only, only];
    expect(parseTronBlock(block, contracts)).toEqual([]);
  });

  it('ignores contracts outside the configured set (case-insensitive match)', () => {
    const block = makeBlock(transferData(TO_HEX_BODY, 5n));
    block.transactions![0]!.raw_data!.contract![0]!.parameter!.value!.contract_address = USDT_HEX.toUpperCase();
    expect(parseTronBlock(block, contracts)).toHaveLength(1);

    block.transactions![0]!.raw_data!.contract![0]!.parameter!.value!.contract_address =
      '41deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    expect(parseTronBlock(block, contracts)).toEqual([]);
  });

  it('isTronHash accepts 64 hex chars only', () => {
    expect(isTronHash('a'.repeat(64))).toBe(true);
    expect(isTronHash('A'.repeat(64))).toBe(true);
    expect(isTronHash(`0x${'a'.repeat(64)}`)).toBe(false);
    expect(isTronHash('a'.repeat(63))).toBe(false);
    expect(isTronHash(null)).toBe(false);
  });
});
