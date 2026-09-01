import { describe, expect, it } from 'vitest';
import {
  buildTronReport,
  decodeTrc20TransferData,
  parseTronBlock,
  type TronBlock,
} from '../src/scanner/tronDecode.js';
import type { TokenConfig } from '../src/types.js';

const USDT: TokenConfig = { symbol: 'USDT', contract_address: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', decimals: 6 };
const USDT_HEX = '41a614f803b6fd780986a42c78ec9c7f77e6ded13c';

// m/44'/195'/0'/0/0 от тестовой мнемоники
const TO_BASE58 = 'TWer2Ygk5TEheHp3TPuYeqxmB6SsGZmaL6';
const TO_HEX_BODY = 'e2e1a54926527fbb4e4420de4c6bab82beaee24d';
const OWNER_HEX = '41977c20977f412c2a1aa4ef3d49fe8f5f9a1f5b09';

const contracts = new Map<string, TokenConfig>([[USDT_HEX, USDT]]);

/** data = a9059cbb ‖ to(32 bytes, right-aligned) ‖ amount(32 bytes). */
function transferData(toHexBody: string, amount: bigint): string {
  return `a9059cbb${'0'.repeat(24)}${toHexBody}${amount.toString(16).padStart(64, '0')}`;
}

function makeBlock(txs: unknown[], number = 58_000_000): TronBlock {
  return {
    blockID: '0000000003757bd0fc8b1c1ab7b8c8f9b6f1d0c1f0a9e8d7c6b5a4938271605f',
    block_header: {
      raw_data: {
        number,
        parentHash: '0000000003757bcf0000000000000000000000000000000000000000000000aa',
        timestamp: 1_735_000_000_000,
      },
    },
    transactions: txs as TronBlock['transactions'],
  };
}

function makeTx(overrides: Record<string, unknown> = {}, data = transferData(TO_HEX_BODY, 100_500_000n)) {
  return {
    ret: [{ contractRet: 'SUCCESS' }],
    txID: 'c1b2a3947f5e6d8c9b0a1f2e3d4c5b6a7988776655443322110099aabbccddee',
    raw_data: {
      contract: [
        {
          type: 'TriggerSmartContract',
          parameter: {
            value: { data, owner_address: OWNER_HEX, contract_address: USDT_HEX },
            type_url: 'type.googleapis.com/protocol.TriggerSmartContract',
          },
        },
      ],
    },
    ...overrides,
  };
}

describe('TRC-20 data decoding', () => {
  it('decodes the transfer(address,uint256) payload', () => {
    const decoded = decodeTrc20TransferData(transferData(TO_HEX_BODY, 100_500_000n));
    expect(decoded).not.toBeNull();
    expect(decoded!.toHex).toBe(`41${TO_HEX_BODY}`);
    expect(decoded!.valueRaw).toBe(100_500_000n);
  });

  it('tolerates a 0x prefix and uppercase hex', () => {
    const decoded = decodeTrc20TransferData(`0x${transferData(TO_HEX_BODY, 1n).toUpperCase()}`);
    expect(decoded!.valueRaw).toBe(1n);
  });

  it('rejects other selectors and truncated data', () => {
    expect(decodeTrc20TransferData(transferData(TO_HEX_BODY, 1n).replace('a9059cbb', '23b872dd'))).toBeNull();
    expect(decodeTrc20TransferData('a9059cbb00')).toBeNull();
    expect(decodeTrc20TransferData('')).toBeNull();
  });
});

describe('Tron block parsing', () => {
  it('extracts a successful TRC-20 transfer to a watched contract', () => {
    const transfers = parseTronBlock(makeBlock([makeTx()]), contracts);
    expect(transfers).toHaveLength(1);

    const t = transfers[0]!;
    expect(t.to).toBe(TO_BASE58);
    expect(t.toHex).toBe(`41${TO_HEX_BODY}`);
    expect(t.from).toMatch(/^T[1-9A-HJ-NP-Za-km-z]{33}$/);
    expect(t.valueRaw).toBe(100_500_000n);
    expect(t.contractHex).toBe(USDT_HEX);
    expect(t.blockNumber).toBe(58_000_000);
    expect(t.blockHash).toBe('0000000003757bd0fc8b1c1ab7b8c8f9b6f1d0c1f0a9e8d7c6b5a4938271605f');
  });

  it('skips reverted transactions', () => {
    expect(parseTronBlock(makeBlock([makeTx({ ret: [{ contractRet: 'REVERT' }] })]), contracts)).toHaveLength(0);
    expect(parseTronBlock(makeBlock([makeTx({ ret: [] })]), contracts)).toHaveLength(0);
  });

  it('skips non-TriggerSmartContract transactions', () => {
    const transferContract = {
      ret: [{ contractRet: 'SUCCESS' }],
      txID: 'aa',
      raw_data: {
        contract: [{ type: 'TransferContract', parameter: { value: { amount: 1, owner_address: OWNER_HEX } } }],
      },
    };
    expect(parseTronBlock(makeBlock([transferContract]), contracts)).toHaveLength(0);
  });

  it('skips transfers of unwatched token contracts', () => {
    const other = makeTx();
    other.raw_data.contract[0]!.parameter.value.contract_address = '413487b63d30b5b2c87fb7ffa8bcfade38eaac1abe';
    expect(parseTronBlock(makeBlock([other]), new Map([[USDT_HEX, USDT]]))).toHaveLength(0);
  });

  it('handles empty and malformed blocks without throwing', () => {
    expect(parseTronBlock(makeBlock([]), contracts)).toHaveLength(0);
    expect(parseTronBlock({}, contracts)).toHaveLength(0);
    expect(parseTronBlock({ block_header: { raw_data: { number: 1 } } }, contracts)).toHaveLength(0);
  });

  it('parses several transfers from one block', () => {
    const second = makeTx(
      { txID: 'ffee0011223344556677889900aabbccddeeff00112233445566778899aabbcc' },
      transferData(TO_HEX_BODY, 1n),
    );
    const transfers = parseTronBlock(makeBlock([makeTx(), second]), contracts);
    expect(transfers.map((t) => t.valueRaw)).toEqual([100_500_000n, 1n]);
  });

  it('builds the backend payload', () => {
    const transfer = parseTronBlock(makeBlock([makeTx()]), contracts)[0]!;
    const report = buildTronReport(transfer, USDT, 19);

    expect(report).toMatchObject({
      network: 'tron',
      tx_hash: 'c1b2a3947f5e6d8c9b0a1f2e3d4c5b6a7988776655443322110099aabbccddee',
      log_index: 0,
      contract_address: USDT.contract_address,
      symbol: 'USDT',
      to_address: TO_BASE58,
      amount_raw: '100500000',
      amount: '100.5',
      block_number: 58_000_000,
      confirmations: 19,
      status: 'detected',
    });
  });
});
