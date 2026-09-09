#!/bin/sh
# Проверка .env перед запуском: плейсхолдеры из .env.example не должны уехать в бой.
#
# В local/dev/test — предупреждения (стенду они не мешают).
# В любом другом APP_ENV — ошибка и выход 1.
set -eu

ENV_FILE="${1:-.env}"

if [ ! -f "$ENV_FILE" ]; then
    echo "check-env: $ENV_FILE не найден. Скопируйте: cp .env.example .env" >&2
    exit 1
fi

# Читаем только KEY=VALUE, не исполняя файл.
get() {
    sed -n "s/^[[:space:]]*$1=//p" "$ENV_FILE" | tail -n 1 | sed 's/[[:space:]]*$//'
}

APP_ENV="$(get APP_ENV)"
[ -n "$APP_ENV" ] || APP_ENV=production

case "$APP_ENV" in
    local|dev|development|test|testing) STRICT=0 ;;
    *) STRICT=1 ;;
esac

problems=0

fail() {
    problems=$((problems + 1))
    if [ "$STRICT" -eq 1 ]; then
        echo "  ERROR   $1" >&2
    else
        echo "  warning $1" >&2
    fi
}

echo "check-env: APP_ENV=$APP_ENV (strict=$STRICT), файл $ENV_FILE"

[ -n "$(get APP_KEY)" ] || fail "APP_KEY пуст — сессии и шифрованные значения не переживут рестарт (php artisan key:generate --show)"

case "$(get DB_PASSWORD)" in
    ''|secret|password|postgres) fail "DB_PASSWORD — дефолт из .env.example (openssl rand -hex 24)" ;;
esac

case "$(get ADMIN_PASSWORD)" in
    ''|password|admin|secret) fail "ADMIN_PASSWORD — дефолт из .env.example" ;;
esac

case "$(get INTERNAL_API_TOKEN)" in
    ''|change-me*) fail "INTERNAL_API_TOKEN пуст или остался плейсхолдером (openssl rand -hex 32)" ;;
esac

[ -n "$(get REDIS_PASSWORD)" ] || fail "REDIS_PASSWORD пуст — redis работает без аутентификации"

case "$(get APP_DEBUG)" in
    true|TRUE|1) fail "APP_DEBUG=true — трассировки и содержимое env уйдут в ответы API" ;;
esac

case "$(get SIMULATION_ENABLED)" in
    true|TRUE|1) fail "SIMULATION_ENABLED=true — админка умеет создавать транзакции в обход блокчейна" ;;
esac

case "$(get WEBHOOK_ALLOW_PRIVATE)" in
    true|TRUE|1) fail "WEBHOOK_ALLOW_PRIVATE=true — вебхуки смогут бить по внутренним адресам (SSRF)" ;;
esac

if [ -z "$(get EVM_XPUB)" ] && [ -z "$(get TRON_XPUB)" ]; then
    # Не ошибка: адреса задаются в админке (Addresses), xpub — там же на странице Wallet (DEPLOY.md §5).
    echo "  note    EVM_XPUB/TRON_XPUB не заданы в .env — добавьте адреса в админке (Addresses) или xpub (Wallet / make keys)"
fi

# Режим TLS: профиль без домена оставит caddy без сертификата, домен без профиля — nginx только на localhost.
case ",$(get COMPOSE_PROFILES)," in
    *,tls,*)
        [ -n "$(get DOMAIN)" ] || fail "COMPOSE_PROFILES=tls, но DOMAIN пуст (make domain DOMAIN=… EMAIL=…)"
        [ -n "$(get ACME_EMAIL)" ] || fail "COMPOSE_PROFILES=tls, но ACME_EMAIL пуст — Let's Encrypt требует e-mail"
        case "$(get APP_URL)" in
            https://*) ;;
            *) fail "COMPOSE_PROFILES=tls, но APP_URL не https:// — ссылки на оплату и вебхуки уйдут с неверной схемой" ;;
        esac ;;
    *)
        if [ "$(get APP_BIND)" = "127.0.0.1" ]; then
            fail "APP_BIND=127.0.0.1 без профиля tls — снаружи сервис недоступен (COMPOSE_PROFILES=tls или уберите APP_BIND)"
        fi ;;
esac

if [ "$problems" -eq 0 ]; then
    echo "check-env: OK"
    exit 0
fi

if [ "$STRICT" -eq 1 ]; then
    echo "check-env: найдено проблем: $problems — исправьте $ENV_FILE" >&2
    exit 1
fi

echo "check-env: $problems предупреждений (APP_ENV=$APP_ENV, для боевого стенда это ошибки)"
exit 0
