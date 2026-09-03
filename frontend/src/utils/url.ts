/**
 * URL safety helpers.
 *
 * Several URLs rendered by the app originate outside the frontend's trust
 * boundary: `success_url` / `cancel_url` are supplied by the merchant when the
 * invoice is created and are rendered on the *public* checkout page, while
 * `explorer_url` / `explorer_address_url` / `image_url` are stored by admins and
 * echoed back by the API. Binding any of them straight into `:href` or `:src`
 * lets a `javascript:` (or `data:`) URI execute in the visitor's context, which
 * is script execution on a payment page — so every such value goes through
 * `safeUrl()` first and is dropped (rendered as plain text, or omitted) when it
 * is not a plain http(s) link.
 *
 * The WHATWG `URL` parser is used deliberately: it strips embedded tabs and
 * newlines and normalises control characters, so obfuscations like
 * `java\tscript:alert(1)` are resolved to their real scheme before the check.
 */

const SAFE_PROTOCOLS = new Set(['http:', 'https:'])

/**
 * Returns the URL when it resolves to an http(s) location, otherwise `null`.
 * Relative values are resolved against the current origin, so in-app links
 * (`/pay/{id}`) keep working.
 */
export function safeUrl(value: string | null | undefined): string | null {
  if (typeof value !== 'string') return null
  const raw = value.trim()
  if (!raw) return null
  try {
    const base = typeof window === 'undefined' ? undefined : window.location.href
    const parsed = new URL(raw, base)
    return SAFE_PROTOCOLS.has(parsed.protocol) ? parsed.href : null
  } catch {
    return null
  }
}

/** Boolean form of {@link safeUrl}. */
export function isSafeUrl(value: string | null | undefined): boolean {
  return safeUrl(value) !== null
}

/**
 * Same guarantee as {@link safeUrl}, for values used as an image source.
 * `data:` images are rejected on purpose: they are never legitimate here and
 * the production CSP does not allow them from remote-controlled fields.
 */
export function safeImageUrl(value: string | null | undefined): string | null {
  return safeUrl(value)
}

/**
 * A same-origin path safe to hand to `router.push()`. Guards against
 * protocol-relative (`//evil.example`) and backslash-smuggled values, which
 * `startsWith('/')` alone would let through.
 */
export function safeInternalPath(
  value: unknown,
  fallback = '/admin',
): string {
  if (typeof value !== 'string') return fallback
  const raw = value.trim()
  if (!raw.startsWith('/')) return fallback
  // "//host", "/\host" and "/\\host" all resolve to a foreign origin.
  if (/^\/[/\\]/.test(raw)) return fallback
  if (raw.includes('\\')) return fallback
  return raw
}
