<?php

namespace App\Support;

/**
 * SSRF guard for merchant-controlled outbound URLs (webhooks).
 *
 * The backend sits on the compose network next to `watcher`, `postgres` and
 * `redis`, and on a cloud host next to a link-local metadata endpoint. A
 * merchant that can set `webhook_url` would otherwise be able to make the queue
 * worker issue POSTs into any of that.
 *
 * Two layers:
 *   1. Always on — scheme must be http/https, the host must be a public-looking
 *      name (a literal private/reserved IP and a bare, dot-less compose service
 *      name are both rejected).
 *   2. Best effort — the host is resolved and every A/AAAA record must be
 *      public. A host that does not resolve is allowed through: the delivery
 *      will simply fail, and rejecting it would break offline test/dev domains.
 *
 * `WEBHOOK_ALLOW_PRIVATE=true` disables both layers for local development, so
 * the demo flow can point a webhook at `http://host.docker.internal:3000`.
 */
final class OutboundUrlGuard
{
    /** Hostnames that must never be reachable, whatever DNS says. */
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata',
        'instance-data',
        'host.docker.internal',
        'gateway.docker.internal',
    ];

    /** Suffixes that are internal by definition (mDNS, k8s, cloud metadata). */
    private const BLOCKED_SUFFIXES = [
        '.internal',
        '.local',
        '.localdomain',
        '.localhost',
        '.svc',
        '.svc.cluster.local',
    ];

    public static function allowsPrivate(): bool
    {
        return (bool) config('services.webhooks.allow_private', false);
    }

    /**
     * @return string|null null when the URL is safe to call, otherwise a short
     *                     human-readable reason it was rejected
     */
    public static function reject(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'The URL is empty.';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return 'The URL is not absolute.';
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'Only http and https URLs are allowed.';
        }

        if (self::allowsPrivate()) {
            return null;
        }

        $host = mb_strtolower(trim($parts['host'], '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'This host is not allowed.';
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return 'Internal host names are not allowed.';
            }
        }

        // A literal IP is checked directly; no DNS is involved.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? null : 'Private and reserved IP addresses are not allowed.';
        }

        // `http://watcher:3100` and friends: a dot-less name can only be a
        // container/LAN name, never a public domain.
        if (! str_contains($host, '.')) {
            return 'Internal host names are not allowed.';
        }

        foreach (self::resolve($host) as $ip) {
            if (! self::isPublicIp($ip)) {
                return 'This host resolves to a private or reserved address.';
            }
        }

        return null;
    }

    public static function isAllowed(string $url): bool
    {
        return self::reject($url) === null;
    }

    /**
     * Private (10/8, 172.16/12, 192.168/16, fc00::/7, fe80::/10) and reserved
     * (0.0.0.0/8, 127/8, 169.254/16, 240/4, ::1) ranges are all covered by the
     * two filter flags.
     */
    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return array<int, string> resolved addresses; empty when the host does
     *                            not resolve (see the class docblock)
     */
    private static function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
