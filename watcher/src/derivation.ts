import { HDNodeVoidWallet, HDNodeWallet, Mnemonic, SigningKey, getBytes, keccak256, randomBytes } from 'ethers';
import type { NetworkCode } from './types.js';
import { base58CheckEncode } from './tronAddress.js';

export type DerivationFamily = 'evm' | 'tron';

export const ACCOUNT_PATHS: Record<DerivationFamily, string> = {
  evm: "m/44'/60'/0'",
  tron: "m/44'/195'/0'",
};

export function familyFor(network: NetworkCode): DerivationFamily {
  return network === 'tron' ? 'tron' : 'evm';
}

export function fullPath(network: NetworkCode, index: number): string {
  return `${ACCOUNT_PATHS[familyFor(network)]}/0/${index}`;
}

/** Tron-адрес из несжатого публичного ключа: base58check(0x41 ‖ last20(keccak256(pub[1:]))). */
export function tronAddressFromPublicKey(publicKey: string): string {
  const uncompressed = SigningKey.computePublicKey(publicKey, false); // 0x04 ‖ X ‖ Y
  const hash = keccak256(`0x${uncompressed.slice(4)}`);
  return base58CheckEncode(getBytes(`0x41${hash.slice(-40)}`));
}

export interface DerivedAddress {
  network: NetworkCode;
  index: number;
  path: string;
  address: string;
}

/**
 * Деривация депозитных адресов из account-level xpub (m/44'/{coin}'/0').
 * Дочерний путь всегда несклонённый `0/{index}` — xpub приватных ключей не содержит.
 */
export class Deriver {
  private readonly roots = new Map<DerivationFamily, HDNodeVoidWallet>();

  constructor(xpubs: { evm?: string; tron?: string }) {
    if (xpubs.evm) this.roots.set('evm', Deriver.externalChain(xpubs.evm));
    if (xpubs.tron) this.roots.set('tron', Deriver.externalChain(xpubs.tron));
  }

  private static externalChain(xpub: string): HDNodeVoidWallet {
    const node = HDNodeWallet.fromExtendedKey(xpub.trim());
    if (node.depth !== 3) {
      throw new Error(`expected account-level xpub (depth 3), got depth ${node.depth}`);
    }
    // Ветка "0" = external chain, дальше деривуем индексы.
    return node.deriveChild(0) as HDNodeVoidWallet;
  }

  has(family: DerivationFamily): boolean {
    return this.roots.has(family);
  }

  get ready(): boolean {
    return this.roots.size > 0;
  }

  supports(network: NetworkCode): boolean {
    return this.has(familyFor(network));
  }

  derive(network: NetworkCode, index: number): DerivedAddress {
    if (!Number.isInteger(index) || index < 0 || index > 0x7fffffff) {
      throw new Error(`derivation index out of range: ${index}`);
    }
    const family = familyFor(network);
    const root = this.roots.get(family);
    if (!root) {
      throw new XpubMissingError(family);
    }
    const child = root.deriveChild(index);
    const address = family === 'tron' ? tronAddressFromPublicKey(child.publicKey) : child.address;
    return { network, index, path: fullPath(network, index), address };
  }

  deriveBatch(network: NetworkCode, from: number, count: number): DerivedAddress[] {
    const out: DerivedAddress[] = [];
    for (let i = 0; i < count; i += 1) {
      out.push(this.derive(network, from + i));
    }
    return out;
  }
}

export class XpubMissingError extends Error {
  constructor(public readonly family: DerivationFamily) {
    super(`xpub for ${family} is not configured`);
    this.name = 'XpubMissingError';
  }
}

export interface GeneratedKeys {
  mnemonic: string;
  evmXpub: string;
  tronXpub: string;
}

/** Генерация мнемоники (12 или 24 слова) и account-level xpub'ов для обеих семей. */
export function generateKeys(words: 12 | 24 = 12): GeneratedKeys {
  const entropy = randomBytes(words === 24 ? 32 : 16);
  const mnemonic = Mnemonic.fromEntropy(entropy);
  return { mnemonic: mnemonic.phrase, ...xpubsFromMnemonic(mnemonic.phrase) };
}

export function xpubsFromMnemonic(phrase: string): { evmXpub: string; tronXpub: string } {
  const evm = HDNodeWallet.fromPhrase(phrase, undefined, ACCOUNT_PATHS.evm);
  const tron = HDNodeWallet.fromPhrase(phrase, undefined, ACCOUNT_PATHS.tron);
  return { evmXpub: evm.neuter().extendedKey, tronXpub: tron.neuter().extendedKey };
}
