import { formatUnits } from 'ethers';

/**
 * raw -> человеко-читаемая строка без хвостовых нулей ("100000000", 6) => "100".
 * Суммы везде передаются строками (SPEC §2).
 */
export function formatAmount(raw: bigint | string, decimals: number): string {
  const s = formatUnits(BigInt(raw), decimals);
  if (!s.includes('.')) return s;
  const trimmed = s.replace(/0+$/, '').replace(/\.$/, '');
  return trimmed === '' || trimmed === '-' ? '0' : trimmed;
}
