<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Writes the `audit_logs` trail for sensitive admin actions (SPEC §4).
 *
 * Values are recorded so an operator can answer "who changed this and to
 * what", so anything secret-shaped is redacted before it is stored — an audit
 * table is a database row like any other and must not become the place a
 * webhook secret or password ends up in plaintext.
 */
class AuditLogger
{
    /** Keys whose values are never written to the trail. */
    private const REDACTED = [
        'password', 'password_confirmation', 'webhook_secret', 'key', 'key_hash',
        'plaintext', 'token', 'secret', 'api_key',
    ];

    /**
     * @param  array<string, mixed>  $changes
     */
    public function log(string $action, ?Model $subject = null, array $changes = [], ?User $user = null): AuditLog
    {
        $user ??= self::currentUser();

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'changes' => $changes === [] ? null : self::redact($changes),
            'ip' => request()?->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function redact(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (in_array(mb_strtolower((string) $key), self::REDACTED, true)) {
                $changes[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $changes[$key] = self::redact($value);
            }
        }

        return $changes;
    }

    /**
     * The subset of a model's attributes that actually changed, for an update.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    public static function diff(array $before, Model $after): array
    {
        $changed = [];

        foreach ($after->getAttributes() as $key => $value) {
            if (! Arr::exists($before, $key) || $before[$key] != $value) {
                $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        unset($changed['updated_at']);

        return $changed;
    }

    private static function currentUser(): ?User
    {
        $user = request()?->user();

        return $user instanceof User ? $user : null;
    }
}
