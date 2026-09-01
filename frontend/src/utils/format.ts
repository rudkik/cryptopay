/**
 * Formatting helpers.
 *
 * IMPORTANT: monetary amounts arrive as decimal strings with up to 18 decimals.
 * They are never converted to `number` — every helper here works on the string
 * representation so that precision is preserved exactly.
 */

const AMOUNT_RE = /^-?\d+(\.\d+)?$/

/** Split "-123.4500" -> { sign:'-', int:'123', frac:'4500' } */
function splitAmount(value: string): { sign: string; int: string; frac: string } | null {
  const raw = value.trim()
  if (!AMOUNT_RE.test(raw)) return null
  const sign = raw.startsWith('-') ? '-' : ''
  const unsigned = sign ? raw.slice(1) : raw
  const [int, frac = ''] = unsigned.split('.')
  return { sign, int: int.replace(/^0+(?=\d)/, ''), frac }
}

/** U+2009 THIN SPACE — groups digits without breaking the number visually. */
const THIN_SPACE = '\u2009'

/** Group the integer part: "1234567" -> "1\u2009234\u2009567". */
function groupInteger(int: string, separator = THIN_SPACE): string {
  return int.replace(/\B(?=(\d{3})+(?!\d))/g, separator)
}

/**
 * Trim trailing zeros from the fractional part using string ops only.
 * "100.000000" -> "100", "0.5000" -> "0.5", "12" -> "12"
 */
export function trimAmount(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '0'
  const parts = splitAmount(String(value))
  if (!parts) return String(value)
  const frac = parts.frac.replace(/0+$/, '')
  const int = parts.int === '' ? '0' : parts.int
  return `${parts.sign}${int}${frac ? `.${frac}` : ''}`
}

export interface FormatAmountOptions {
  /** Keep at least this many decimals (pads with zeros). */
  minDecimals?: number
  /** Never show more than this many decimals (truncates, does not round up). */
  maxDecimals?: number
  /** Insert thin spaces between thousands. */
  group?: boolean
}

/** Human-readable amount, precision-safe. */
export function formatAmount(
  value: string | number | null | undefined,
  options: FormatAmountOptions = {},
): string {
  const { minDecimals = 0, maxDecimals = 8, group = true } = options
  if (value === null || value === undefined || value === '') return '0'
  const parts = splitAmount(String(value))
  if (!parts) return String(value)

  let frac = parts.frac
  if (frac.length > maxDecimals) frac = frac.slice(0, maxDecimals)
  frac = frac.replace(/0+$/, '')
  while (frac.length < minDecimals) frac += '0'

  const int = groupInteger(parts.int === '' ? '0' : parts.int, group ? ' ' : '')
  return `${parts.sign}${int}${frac ? `.${frac}` : ''}`
}

/** Amount split into the "big" and the de-emphasised fractional tail. */
export function splitForDisplay(
  value: string | number | null | undefined,
  maxDecimals = 8,
): { head: string; tail: string } {
  const formatted = formatAmount(value, { maxDecimals })
  const dot = formatted.indexOf('.')
  if (dot === -1) return { head: formatted, tail: '' }
  return { head: formatted.slice(0, dot), tail: formatted.slice(dot) }
}

/** Compare two decimal strings without floats. Returns -1 | 0 | 1. */
export function compareAmounts(a: string, b: string): number {
  const pa = splitAmount(a || '0')
  const pb = splitAmount(b || '0')
  if (!pa || !pb) return 0
  if (pa.sign !== pb.sign) return pa.sign === '-' ? -1 : 1
  const flip = pa.sign === '-' ? -1 : 1

  const ia = pa.int || '0'
  const ib = pb.int || '0'
  if (ia.length !== ib.length) return (ia.length > ib.length ? 1 : -1) * flip
  if (ia !== ib) return (ia > ib ? 1 : -1) * flip

  const len = Math.max(pa.frac.length, pb.frac.length)
  const fa = pa.frac.padEnd(len, '0')
  const fb = pb.frac.padEnd(len, '0')
  if (fa === fb) return 0
  return (fa > fb ? 1 : -1) * flip
}

/** Scale a decimal string into a BigInt with `scale` fractional digits. */
function toScaled(value: string, scale: number): bigint {
  const parts = splitAmount(value || '0')
  if (!parts) return 0n
  const frac = parts.frac.padEnd(scale, '0').slice(0, scale)
  return BigInt(`${parts.sign}${parts.int || '0'}${frac}`)
}

/** Render a scaled BigInt back to a decimal string. */
function fromScaled(value: bigint, scale: number): string {
  const negative = value < 0n
  const digits = (negative ? -value : value).toString().padStart(scale + 1, '0')
  const int = digits.slice(0, digits.length - scale)
  const frac = scale > 0 ? digits.slice(digits.length - scale) : ''
  const out = frac ? `${int}.${frac}` : int
  return trimAmount(`${negative ? '-' : ''}${out}`)
}

/**
 * Exact `a - b` on decimal strings (BigInt arithmetic, no float rounding).
 * Returns "0" when the result would be negative and `clampToZero` is set.
 */
export function subtractAmounts(
  a: string | null | undefined,
  b: string | null | undefined,
  clampToZero = false,
): string {
  const left = String(a ?? '0')
  const right = String(b ?? '0')
  if (!AMOUNT_RE.test(left.trim()) || !AMOUNT_RE.test(right.trim())) return trimAmount(left)
  const scale = Math.max(
    (left.split('.')[1] ?? '').length,
    (right.split('.')[1] ?? '').length,
    0,
  )
  const result = toScaled(left, scale) - toScaled(right, scale)
  if (clampToZero && result < 0n) return '0'
  return fromScaled(result, scale)
}

/**
 * Percentage of `part` relative to `total`, clamped to [0, 100].
 * Uses Number only for the ratio (display-only, never for money).
 */
export function percentOf(part: string | null | undefined, total: string | null | undefined): number {
  const p = Number(part ?? '0')
  const t = Number(total ?? '0')
  if (!Number.isFinite(p) || !Number.isFinite(t) || t <= 0) return 0
  return Math.max(0, Math.min(100, (p / t) * 100))
}

/** 0x1234…abcd — truncate the middle of an address or hash. */
export function truncateMiddle(value: string | null | undefined, head = 8, tail = 6): string {
  if (!value) return '—'
  if (value.length <= head + tail + 1) return value
  return `${value.slice(0, head)}…${value.slice(-tail)}`
}

const DATE_TIME: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', DATE_TIME).format(d)
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', { year: 'numeric', month: 'short', day: '2-digit' }).format(d)
}

export function formatShortDate(value: string | null | undefined): string {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return new Intl.DateTimeFormat('en-GB', { month: 'short', day: '2-digit' }).format(d)
}

/** "3 min ago" / "in 2 h" */
export function formatRelative(value: string | null | undefined): string {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'
  const diffMs = d.getTime() - Date.now()
  const abs = Math.abs(diffMs)
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 1000],
    ['minute', 60_000],
    ['hour', 3_600_000],
    ['day', 86_400_000],
  ]
  let unit: Intl.RelativeTimeFormatUnit = 'day'
  let divisor = 86_400_000
  for (let i = units.length - 1; i >= 0; i -= 1) {
    if (abs >= units[i]![1] || i === 0) {
      unit = units[i]![0]
      divisor = units[i]![1]
      break
    }
  }
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
  return rtf.format(Math.round(diffMs / divisor), unit)
}

/** Seconds -> "01:23:45" or "23:45". */
export function formatDuration(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds))
  const hours = Math.floor(s / 3600)
  const minutes = Math.floor((s % 3600) / 60)
  const seconds = s % 60
  const pad = (n: number) => String(n).padStart(2, '0')
  return hours > 0 ? `${pad(hours)}:${pad(minutes)}:${pad(seconds)}` : `${pad(minutes)}:${pad(seconds)}`
}

/** Compact integer for stat tiles: 12 400 -> "12.4k". */
export function formatCount(value: number | null | undefined): string {
  const n = Number(value ?? 0)
  if (!Number.isFinite(n)) return '0'
  if (Math.abs(n) < 10_000) return new Intl.NumberFormat('en-US').format(n)
  return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n)
}

export function titleCase(value: string): string {
  return value
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}
