/**
 * Маскирование секретов в текстах, которые уходят в логи и в /health.
 *
 * Мотивация: сообщения об ошибках ethers содержат полный `requestUrl` RPC-ноды,
 * а у платных провайдеров ключ живёт прямо в URL
 * (`https://mainnet.infura.io/v3/<KEY>`, `?apikey=<KEY>`). `/health` отдаётся
 * без авторизации, поэтому непромаскированный URL = утечка ключа.
 */

const URL_RE = /\b(https?|wss?):\/\/[^\s"'<>,)\]]+/gi;

/** Дополнительные секреты (ключи API, токены), которые надо вырезать по значению. */
const secrets = new Set<string>();

/** Регистрирует значение как секрет: любое его вхождение будет вырезано. */
export function registerSecret(value: string | undefined | null): void {
  if (typeof value !== 'string') return;
  const trimmed = value.trim();
  // Короткие значения вырезать опасно: попадём в обычный текст.
  if (trimmed.length < 8) return;
  secrets.add(trimmed);
}

/** Только для тестов. */
export function clearSecrets(): void {
  secrets.clear();
}

/**
 * URL -> `scheme://host` (+ `/***`, если был путь или query).
 * Пользовательская часть (`user:pass@`) и порт-«секреты» отбрасываются целиком.
 */
export function maskUrl(raw: string): string {
  let trailing = '';
  let candidate = raw;
  // Не съедаем знаки препинания, приклеившиеся к URL в тексте ошибки.
  while (candidate.length > 0 && /[.,;:!?]$/.test(candidate)) {
    trailing = candidate.slice(-1) + trailing;
    candidate = candidate.slice(0, -1);
  }

  let url: URL;
  try {
    url = new URL(candidate);
  } catch {
    return '[redacted-url]';
  }

  const hasSecretPath = (url.pathname !== '' && url.pathname !== '/') || url.search !== '';
  const host = url.port ? `${url.hostname}:${url.port}` : url.hostname;
  return `${url.protocol}//${host}${hasSecretPath ? '/***' : ''}${trailing}`;
}

/** Маскирует URL-ы и зарегистрированные секреты в произвольном тексте. */
export function redactSecrets(text: string): string {
  let out = text.replace(URL_RE, (m) => maskUrl(m));
  for (const secret of secrets) {
    if (secret && out.includes(secret)) {
      out = out.split(secret).join('[redacted]');
    }
  }
  return out;
}

/** Хвост секрета для логов: `…abcd` (по нему можно сверить конфиг, но не восстановить). */
export function secretFingerprint(value: string): string {
  if (!value) return '(empty)';
  if (value.length <= 4) return '***';
  return `***${value.slice(-4)} (len=${value.length})`;
}
