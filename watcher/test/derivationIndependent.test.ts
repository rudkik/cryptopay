import { createHash } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { HDNodeWallet, SigningKey, keccak256 } from 'ethers';
import { Deriver, deriveWithXpub, xpubsFromMnemonic } from '../src/derivation.js';

/**
 * Перекрёстная проверка деривации против НЕЗАВИСИМОЙ реализации:
 *  - EVM: полный путь m/44'/60'/0'/0/{i} от мнемоники напрямую через ethers,
 *    минуя xpub и Deriver.externalChain;
 *  - Tron: keccak(pub[1:]) + собственный base58check на node:crypto (без ethers).
 *
 * Смысл: адрес — это то, куда клиент пришлёт деньги. Ошибка здесь означает,
 * что средства уйдут по адресу, ключа от которого у владельца мнемоники нет.
 */

const TEST_MNEMONIC = 'test test test test test test test test test test test junk';

/** Известный вектор (аккаунты hardhat/anvil для этой мнемоники). */
const KNOWN_EVM = [
  '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
  '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
  '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC',
];

const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

/** base58check без ethers и без bs58 — только node:crypto. */
function base58check(payload: Buffer): string {
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

/** Ожидаемый Tron-адрес, посчитанный по определению из SPEC §3. */
function expectedTronAddress(index: number): string {
  const node = HDNodeWallet.fromPhrase(TEST_MNEMONIC, undefined, `m/44'/195'/0'/0/${index}`);
  const uncompressed = SigningKey.computePublicKey(node.publicKey, false); // 0x04 ‖ X ‖ Y
  const hash = keccak256(`0x${uncompressed.slice(4)}`);
  return base58check(Buffer.from(`41${hash.slice(-40)}`, 'hex'));
}

describe('деривация против независимой реализации', () => {
  const { evmXpub, tronXpub } = xpubsFromMnemonic(TEST_MNEMONIC);
  const deriver = new Deriver({ evm: evmXpub, tron: tronXpub });

  it('base58check из node:crypto согласован с известными mainnet-контрактами', () => {
    expect(base58check(Buffer.from('41a614f803b6fd780986a42c78ec9c7f77e6ded13c', 'hex'))).toBe(
      'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
    );
    expect(base58check(Buffer.from('413487b63d30b5b2c87fb7ffa8bcfade38eaac1abe', 'hex'))).toBe(
      'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8',
    );
  });

  for (const index of [0, 1, 2]) {
    it(`index ${index}: EVM совпадает с полным путём ethers и известным вектором`, () => {
      const fromFullPath = HDNodeWallet.fromPhrase(TEST_MNEMONIC, undefined, `m/44'/60'/0'/0/${index}`);
      const derived = deriver.derive('ethereum', index);

      expect(derived.address).toBe(fromFullPath.address);
      expect(derived.address).toBe(KNOWN_EVM[index]);
      expect(derived.path).toBe(`m/44'/60'/0'/0/${index}`);
      // bsc делит xpub с ethereum (SPEC §3)
      expect(deriver.derive('bsc', index).address).toBe(derived.address);
      // ad-hoc xpub из тела запроса даёт то же самое
      expect(deriveWithXpub('ethereum', index, evmXpub).address).toBe(derived.address);
    });

    it(`index ${index}: Tron совпадает с независимым base58check(0x41 ‖ keccak)`, () => {
      const derived = deriver.derive('tron', index);
      expect(derived.address).toBe(expectedTronAddress(index));
      expect(derived.path).toBe(`m/44'/195'/0'/0/${index}`);
      expect(deriveWithXpub('tron', index, tronXpub).address).toBe(derived.address);
    });
  }
});
