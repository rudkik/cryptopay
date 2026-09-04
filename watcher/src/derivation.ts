import {
  HDNodeVoidWallet,
  HDNodeWallet,
  Mnemonic,
  SigningKey,
  decodeBase58,
  getBytes,
  keccak256,
  randomBytes,
  sha256,
  toBeArray,
} from 'ethers';
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

/**
 * Невалидный ad-hoc xpub из тела запроса (см. deriveWithXpub/deriveBatchWithXpub).
 * `reason` — короткое человекочитаемое объяснение и НИКОГДА не содержит сам xpub:
 * этот текст уходит в HTTP-ответ и в логи как есть.
 */
export class InvalidXpubError extends Error {
  constructor(public readonly reason: string) {
    super(`invalid xpub: ${reason}`);
    this.name = 'InvalidXpubError';
  }
}

const XPUB_PREFIX = 'xpub';
/** payload(78) + checksum(4) — see BIP32 serialization format. */
const XPUB_SERIALIZED_BYTES = 78;
const XPUB_CHECKSUM_BYTES = 4;
const XPUB_TOTAL_BYTES = XPUB_SERIALIZED_BYTES + XPUB_CHECKSUM_BYTES;

/** base58check: payload и его 4-байтный checksum = первые 4 байта sha256(sha256(payload)). */
function assertBase58Checksum(trimmed: string): void {
  let decoded: bigint;
  try {
    decoded = decodeBase58(trimmed);
  } catch {
    throw new InvalidXpubError('not valid base58');
  }

  // decodeBase58 возвращает BigInt и теряет ведущие нулевые байты — досчитываем
  // их обратно левым паддингом до полного сериализованного размера (82 байта).
  let bytes = getBytes(toBeArray(decoded));
  if (bytes.length > XPUB_TOTAL_BYTES) {
    throw new InvalidXpubError(`decoded xpub has unexpected length (${bytes.length} bytes)`);
  }
  if (bytes.length < XPUB_TOTAL_BYTES) {
    const padded = new Uint8Array(XPUB_TOTAL_BYTES);
    padded.set(bytes, XPUB_TOTAL_BYTES - bytes.length);
    bytes = padded;
  }

  const payload = bytes.slice(0, XPUB_SERIALIZED_BYTES);
  const checksum = bytes.slice(XPUB_SERIALIZED_BYTES);
  const expected = getBytes(sha256(getBytes(sha256(payload)))).slice(0, XPUB_CHECKSUM_BYTES);
  for (let i = 0; i < XPUB_CHECKSUM_BYTES; i += 1) {
    if (checksum[i] !== expected[i]) {
      throw new InvalidXpubError('checksum mismatch');
    }
  }
}

/**
 * LRU-кэш на 8 распарсенных account-узлов (external chain, m/44'/{coin}'/0'/0),
 * ключ — `${family}:${trimmed xpub}`. Без кэша каждый вызов /addresses/derive(-batch)
 * с ad-hoc xpub из админки заново парсил бы base58check и заново деривовал BIP32-узел —
 * дёшево по отдельности, но превью в админке дёргает эндпоинт часто.
 */
const XPUB_CACHE_MAX = 8;
const xpubRootCache = new Map<string, HDNodeVoidWallet>();

function cacheKey(family: DerivationFamily, trimmed: string): string {
  return `${family}:${trimmed}`;
}

function touchCache(key: string, node: HDNodeVoidWallet): void {
  // Map сохраняет порядок вставки: удалить+вставить = переместить в «недавно использованные».
  xpubRootCache.delete(key);
  xpubRootCache.set(key, node);
  if (xpubRootCache.size > XPUB_CACHE_MAX) {
    const oldestKey = xpubRootCache.keys().next().value;
    if (oldestKey !== undefined) xpubRootCache.delete(oldestKey);
  }
}

/** Валидация + разбор ad-hoc xpub, см. правила a-d в комментарии к deriveWithXpub. */
function externalChainForXpub(family: DerivationFamily, xpub: string): HDNodeVoidWallet {
  const trimmed = typeof xpub === 'string' ? xpub.trim() : '';
  const key = cacheKey(family, trimmed);
  const cached = xpubRootCache.get(key);
  if (cached) {
    touchCache(key, cached);
    return cached;
  }

  if (trimmed === '') {
    throw new InvalidXpubError('xpub must not be empty');
  }
  if (!trimmed.startsWith(XPUB_PREFIX)) {
    throw new InvalidXpubError('expected a mainnet account-level xpub (must start with "xpub")');
  }
  assertBase58Checksum(trimmed);

  let parsed: HDNodeWallet | HDNodeVoidWallet;
  try {
    parsed = HDNodeWallet.fromExtendedKey(trimmed);
  } catch {
    throw new InvalidXpubError('failed to parse xpub');
  }
  if (parsed.depth !== 3) {
    throw new InvalidXpubError(`expected account-level xpub (depth 3), got depth ${parsed.depth}`);
  }

  // Ветка "0" = external chain, дальше деривуем индексы (как и в Deriver.externalChain).
  const node = parsed.deriveChild(0) as HDNodeVoidWallet;
  touchCache(key, node);
  return node;
}

/**
 * Деривация одного адреса от ad-hoc xpub из тела запроса, а не из env.
 * Валидация в строгом порядке (все ошибки — InvalidXpubError с коротким reason,
 * который никогда не содержит сам xpub):
 *   a) непустая строка после trim, начинается с "xpub" (mainnet public);
 *      xprv/ypub/zpub/tpub отклоняются этой же проверкой префикса;
 *   b) base58check: 82-байтный payload+checksum, checksum = sha256(sha256(payload))[0:4];
 *   c) HDNodeWallet.fromExtendedKey() парсится без исключений;
 *   d) depth === 3 (account-level), как и для env-xpub в Deriver.
 */
export function deriveWithXpub(network: NetworkCode, index: number, xpub: string): DerivedAddress {
  if (!Number.isInteger(index) || index < 0 || index > 0x7fffffff) {
    throw new Error(`derivation index out of range: ${index}`);
  }
  const family = familyFor(network);
  const root = externalChainForXpub(family, xpub);
  const child = root.deriveChild(index);
  const address = family === 'tron' ? tronAddressFromPublicKey(child.publicKey) : child.address;
  return { network, index, path: fullPath(network, index), address };
}

/** Батч поверх deriveWithXpub; распарсенный корень переиспользуется через LRU-кэш. */
export function deriveBatchWithXpub(
  network: NetworkCode,
  from: number,
  count: number,
  xpub: string,
): DerivedAddress[] {
  const out: DerivedAddress[] = [];
  for (let i = 0; i < count; i += 1) {
    out.push(deriveWithXpub(network, from + i, xpub));
  }
  return out;
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
