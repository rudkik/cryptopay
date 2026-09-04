import { HDNodeWallet } from 'ethers';
import { describe, expect, it } from 'vitest';
import {
  ACCOUNT_PATHS,
  Deriver,
  InvalidXpubError,
  XpubMissingError,
  deriveBatchWithXpub,
  deriveWithXpub,
  generateKeys,
  tronAddressFromPublicKey,
  xpubsFromMnemonic,
} from '../src/derivation.js';
import { base58CheckDecode, isValidTronAddress, tronBase58ToHex, tronHexToBase58 } from '../src/tronAddress.js';

const TEST_MNEMONIC = 'test test test test test test test test test test test junk';

// Известный вектор hardhat/anvil: m/44'/60'/0'/0/{0,1,2}
const HARDHAT_ADDRESSES = [
  '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
  '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
  '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
];

describe('HD derivation', () => {
  const { evmXpub, tronXpub } = xpubsFromMnemonic(TEST_MNEMONIC);
  const deriver = new Deriver({ evm: evmXpub, tron: tronXpub });

  it('derives the known hardhat EVM vector from the account xpub', () => {
    const first = deriver.derive('ethereum', 0);
    expect(first.address).toBe(HARDHAT_ADDRESSES[0]);
    expect(first.path).toBe("m/44'/60'/0'/0/0");

    HARDHAT_ADDRESSES.forEach((expected, index) => {
      expect(deriver.derive('ethereum', index).address).toBe(expected);
    });
  });

  it('produces identical addresses for ethereum and bsc (shared EVM xpub)', () => {
    expect(deriver.derive('bsc', 7).address).toBe(deriver.derive('ethereum', 7).address);
    expect(deriver.derive('bsc', 7).path).toBe("m/44'/60'/0'/0/7");
  });

  it('derives valid 34-char base58 T-addresses whose hex form starts with 41', () => {
    for (let i = 0; i < 5; i += 1) {
      const derived = deriver.derive('tron', i);
      expect(derived.path).toBe(`m/44'/195'/0'/0/${i}`);
      expect(derived.address).toMatch(/^T[1-9A-HJ-NP-Za-km-z]{33}$/);
      expect(derived.address).toHaveLength(34);
      expect(isValidTronAddress(derived.address)).toBe(true);

      const hex = tronBase58ToHex(derived.address);
      expect(hex).toMatch(/^41[0-9a-f]{40}$/);
      expect(hex.startsWith('41')).toBe(true);
      // roundtrip base58 -> hex -> base58
      expect(tronHexToBase58(hex)).toBe(derived.address);
    }
  });

  it('matches the documented mainnet hex form of the USDT-TRC20 contract', () => {
    expect(tronBase58ToHex('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t')).toBe(
      '41a614f803b6fd780986a42c78ec9c7f77e6ded13c',
    );
    expect(tronHexToBase58('41a614f803b6fd780986a42c78ec9c7f77e6ded13c')).toBe(
      'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
    );
  });

  it('rejects addresses with a broken checksum', () => {
    const valid = deriver.derive('tron', 0).address;
    const broken = `${valid.slice(0, -1)}${valid.at(-1) === 'a' ? 'b' : 'a'}`;
    expect(isValidTronAddress(broken)).toBe(false);
    expect(() => base58CheckDecode(broken)).toThrow();
  });

  it('derives batches contiguously', () => {
    const batch = deriver.deriveBatch('ethereum', 0, 3);
    expect(batch.map((b) => b.address)).toEqual(HARDHAT_ADDRESSES);
    expect(batch.map((b) => b.index)).toEqual([0, 1, 2]);
  });

  it('throws XpubMissingError when the family xpub is absent', () => {
    const evmOnly = new Deriver({ evm: evmXpub });
    expect(evmOnly.ready).toBe(true);
    expect(evmOnly.supports('tron')).toBe(false);
    expect(() => evmOnly.derive('tron', 0)).toThrow(XpubMissingError);

    const none = new Deriver({});
    expect(none.ready).toBe(false);
    expect(() => none.derive('ethereum', 0)).toThrow(XpubMissingError);
  });

  it('rejects non account-level xpubs', () => {
    expect(() => new Deriver({ evm: 'not-an-xpub' })).toThrow();
  });

  it('rejects out-of-range indexes (hardened range is unreachable from an xpub)', () => {
    expect(() => deriver.derive('ethereum', -1)).toThrow();
    expect(() => deriver.derive('ethereum', 2 ** 31)).toThrow();
  });

  it('generates fresh mnemonics whose xpubs derive consistently', () => {
    for (const words of [12, 24] as const) {
      const keys = generateKeys(words);
      expect(keys.mnemonic.split(' ')).toHaveLength(words);
      expect(keys.evmXpub.startsWith('xpub')).toBe(true);
      expect(keys.tronXpub.startsWith('xpub')).toBe(true);

      const fresh = new Deriver({ evm: keys.evmXpub, tron: keys.tronXpub });
      expect(fresh.derive('ethereum', 0).address).toMatch(/^0x[0-9a-fA-F]{40}$/);
      expect(isValidTronAddress(fresh.derive('tron', 0).address)).toBe(true);
      // xpub, восстановленный из мнемоники, даёт те же адреса
      expect(xpubsFromMnemonic(keys.mnemonic).evmXpub).toBe(keys.evmXpub);
    }
  });

  it('computes a tron address from an uncompressed public key', () => {
    const address = tronAddressFromPublicKey(
      '0x0479be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8',
    );
    expect(isValidTronAddress(address)).toBe(true);
    expect(tronBase58ToHex(address)).toMatch(/^41[0-9a-f]{40}$/);
  });
});

const OTHER_MNEMONIC = 'legal winner thank year wave sausage worth useful legal winner thank yellow';

describe('deriveWithXpub / deriveBatchWithXpub (ad-hoc xpub, bypasses env)', () => {
  const { evmXpub, tronXpub } = xpubsFromMnemonic(OTHER_MNEMONIC);

  it('derives the same address as an equivalent Deriver built from that xpub', () => {
    const viaAdHoc = deriveWithXpub('ethereum', 0, evmXpub);
    const viaDeriver = new Deriver({ evm: evmXpub }).derive('ethereum', 0);
    expect(viaAdHoc).toEqual(viaDeriver);
  });

  it('derives tron addresses the same way as the env path', () => {
    const viaAdHoc = deriveWithXpub('tron', 2, tronXpub);
    const viaDeriver = new Deriver({ tron: tronXpub }).derive('tron', 2);
    expect(viaAdHoc).toEqual(viaDeriver);
    expect(isValidTronAddress(viaAdHoc.address)).toBe(true);
  });

  it('deriveBatchWithXpub produces contiguous indexes and paths', () => {
    const batch = deriveBatchWithXpub('ethereum', 5, 3, evmXpub);
    expect(batch.map((b) => b.index)).toEqual([5, 6, 7]);
    expect(batch.map((b) => b.path)).toEqual(["m/44'/60'/0'/0/5", "m/44'/60'/0'/0/6", "m/44'/60'/0'/0/7"]);
    expect(batch).toEqual(new Deriver({ evm: evmXpub }).deriveBatch('ethereum', 5, 3));
  });

  it('tolerates surrounding whitespace the same way the env path does', () => {
    expect(deriveWithXpub('ethereum', 0, `  ${evmXpub}  `)).toEqual(deriveWithXpub('ethereum', 0, evmXpub));
  });

  it('rejects a non-string-shaped empty xpub', () => {
    expect(() => deriveWithXpub('ethereum', 0, '   ')).toThrow(InvalidXpubError);
  });

  it('rejects xprv/ypub/zpub/tpub prefixes', () => {
    const xprv = HDNodeWallet.fromPhrase(OTHER_MNEMONIC, undefined, ACCOUNT_PATHS.evm).extendedKey;
    expect(xprv.startsWith('xprv')).toBe(true);
    expect(() => deriveWithXpub('ethereum', 0, xprv)).toThrow(InvalidXpubError);
    expect(() => deriveWithXpub('ethereum', 0, 'tpubDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD')).toThrow(
      InvalidXpubError,
    );
  });

  it('rejects a broken base58check checksum with a reason that does not contain the xpub', () => {
    const broken = `${evmXpub.slice(0, -1)}${evmXpub.at(-1) === 'a' ? 'b' : 'a'}`;
    try {
      deriveWithXpub('ethereum', 0, broken);
      throw new Error('should have thrown');
    } catch (err) {
      expect(err).toBeInstanceOf(InvalidXpubError);
      expect((err as InvalidXpubError).reason).not.toContain(broken);
      expect((err as InvalidXpubError).message).not.toContain(broken);
    }
  });

  it('rejects a non-account-level (depth != 3) xpub', () => {
    const deeper = HDNodeWallet.fromPhrase(OTHER_MNEMONIC, undefined, ACCOUNT_PATHS.evm).deriveChild(0).neuter()
      .extendedKey;
    try {
      deriveWithXpub('ethereum', 0, deeper);
      throw new Error('should have thrown');
    } catch (err) {
      expect(err).toBeInstanceOf(InvalidXpubError);
      expect((err as InvalidXpubError).reason).toContain('depth 4');
    }
  });

  it('rejects garbage base58 that merely starts with "xpub"', () => {
    expect(() => deriveWithXpub('ethereum', 0, 'xpub-not-base58-at-all!!!')).toThrow(InvalidXpubError);
  });

  it('caches parsed roots (repeated calls with the same xpub stay consistent and cheap)', () => {
    // Не проверяем внутренности LRU напрямую (приватный модульный Map), но
    // многократный вызов с одним и тем же xpub обязан давать идентичный результат —
    // это и есть наблюдаемый контракт кэша.
    for (let i = 0; i < 20; i += 1) {
      expect(deriveWithXpub('ethereum', 1, evmXpub).address).toBe(
        new Deriver({ evm: evmXpub }).derive('ethereum', 1).address,
      );
    }
  });
});
