import { concat, decodeBase58, encodeBase58, getBytes, hexlify, sha256, toBeHex } from 'ethers';

/** Префикс mainnet-адреса Tron. */
export const TRON_ADDRESS_PREFIX = 0x41;

function checksum(payload: Uint8Array): Uint8Array {
  return getBytes(sha256(sha256(payload))).slice(0, 4);
}

/**
 * base58check над 21-байтовым payload (0x41 ‖ 20 байт).
 * Реализовано на примитивах ethers, чтобы не тащить tronweb/bs58 (SPEC §3).
 */
export function base58CheckEncode(payload: Uint8Array): string {
  if (payload.length !== 21) {
    throw new Error(`tron payload must be 21 bytes, got ${payload.length}`);
  }
  return encodeBase58(concat([payload, checksum(payload)]));
}

export function base58CheckDecode(address: string): Uint8Array {
  const value = decodeBase58(address);
  // 25 байт = 21 payload + 4 checksum; ведущий байт 0x41 ненулевой, поэтому длина фиксирована.
  const bytes = getBytes(toBeHex(value, 25));
  const payload = bytes.slice(0, 21);
  const given = bytes.slice(21);
  const expected = checksum(payload);
  for (let i = 0; i < 4; i += 1) {
    if (given[i] !== expected[i]) {
      throw new Error(`invalid tron address checksum: ${address}`);
    }
  }
  if (payload[0] !== TRON_ADDRESS_PREFIX) {
    throw new Error(`invalid tron address prefix: ${address}`);
  }
  return payload;
}

/** hex "41..." (21 байт, без 0x) -> base58 "T...". */
export function tronHexToBase58(hex: string): string {
  const clean = hex.startsWith('0x') || hex.startsWith('0X') ? hex.slice(2) : hex;
  const normalized = clean.toLowerCase();
  if (!/^41[0-9a-f]{40}$/.test(normalized)) {
    throw new Error(`invalid tron hex address: ${hex}`);
  }
  return base58CheckEncode(getBytes(`0x${normalized}`));
}

/** base58 "T..." -> hex "41..." (без 0x, нижний регистр). */
export function tronBase58ToHex(address: string): string {
  return hexlify(base58CheckDecode(address)).slice(2);
}

export function isValidTronAddress(address: string): boolean {
  try {
    base58CheckDecode(address);
    return true;
  } catch {
    return false;
  }
}
