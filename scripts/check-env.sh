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

if [ -n "$(get EVM_XPUB)" ] || [ -n "$(get TRON_XPUB)" ]; then
    :
else
    fail "EVM_XPUB/TRON_XPUB не заданы — деривация адресов отключена (make keys)"
fi

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
