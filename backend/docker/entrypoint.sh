#!/bin/sh
set -e

# Shared entrypoint for the app, queue and scheduler containers. The command to
# run is passed by compose and exec'd at the end.
#
#   app        : php-fpm
#   queue      : php artisan queue:work redis --queue=default,webhooks --tries=3
#   scheduler  : php artisan schedule:work
#
# Only the `app` container should migrate and seed; the others set
# SKIP_MIGRATIONS=true and just wait for the database.

log() { echo "[entrypoint] $*"; }

# ---------------------------------------------------------------------------
# 1. Wait for Postgres
# ---------------------------------------------------------------------------
DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_USERNAME="${DB_USERNAME:-cryptopay}"
DB_DATABASE="${DB_DATABASE:-cryptopay}"

log "Waiting for Postgres at ${DB_HOST}:${DB_PORT} ..."
attempt=0
until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        log "ERROR: Postgres did not become ready after 60 attempts."
        exit 1
    fi
    sleep 2
done
log "Postgres is ready."

# ---------------------------------------------------------------------------
# 2. Application key
# ---------------------------------------------------------------------------
# Compose passes configuration purely through the environment; there is no .env
# inside the container, so APP_KEY may well be empty on first boot. Generate an
# ephemeral one and export it for this process only.
if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php artisan key:generate --show --no-ansi)"
    export APP_KEY
    log "WARNING: APP_KEY was empty; generated an ephemeral key for this container."
    log "WARNING: Set APP_KEY in your .env to keep sessions and encrypted values"
    log "WARNING: valid across restarts and consistent between containers."
    log "WARNING: APP_KEY=${APP_KEY}"
fi

# ---------------------------------------------------------------------------
# 3. Writable storage
# ---------------------------------------------------------------------------
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
# 4. Migrate, seed, optimise (app container only)
# ---------------------------------------------------------------------------
if [ "${SKIP_MIGRATIONS:-false}" = "true" ]; then
    # Deliberately no config:clear here — if bootstrap/cache is ever shared
    # between containers, clearing it would pull the config cache out from under
    # the app container. Nothing is cached at build time, so there is nothing
    # stale to clear.
    log "SKIP_MIGRATIONS=true — skipping migrate/seed/optimize."
else
    log "Running migrations ..."
    php artisan migrate --force --no-ansi

    log "Seeding (idempotent) ..."
    php artisan db:seed --force --no-ansi

    # `optimize` caches config; it runs only now that APP_KEY is exported, so the
    # cached config can never freeze an empty key.
    log "Caching config, routes and events ..."
    php artisan optimize --no-ansi
fi

log "Starting: $*"
exec "$@"
