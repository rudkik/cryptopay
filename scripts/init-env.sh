#!/bin/sh
# Заполняет секреты в .env, ничего не исполняя из файла и не трогая уже заданные значения.
#   ./scripts/init-env.sh [.env] [--production]
# --production дополнительно выставляет APP_ENV=production, APP_DEBUG=false,
# SIMULATION_ENABLED=false, WEBHOOK_ALLOW_PRIVATE=false.
set -eu

ENV_FILE=".env"
PROD=0
for arg in "$@"; do
    case "$arg" in
        --production) PROD=1 ;;
        *) ENV_FILE="$arg" ;;
    esac
done

[ -f "$ENV_FILE" ] || { cp .env.example "$ENV_FILE"; echo "init-env: создан $ENV_FILE из .env.example"; }
chmod 600 "$ENV_FILE" 2>/dev/null || true

get() { sed -n "s/^[[:space:]]*$1=//p" "$ENV_FILE" | tail -n 1 | sed 's/[[:space:]]*#.*$//; s/[[:space:]]*$//'; }

# set KEY VALUE — заменяет строку KEY=... (или добавляет в конец), значение без пробелов.
set_kv() {
    if grep -qE "^[[:space:]]*$1=" "$ENV_FILE"; then
        sed -i.bak "s|^[[:space:]]*$1=.*|$1=$2|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
    else
        printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
    fi
}

rand_hex() { openssl rand -hex "$1"; }

changed=0
note() { echo "  set     $1"; changed=$((changed + 1)); }

# Пароли БД/Redis нельзя менять, если тома уже созданы: сервер не узнает о новом пароле.
volumes_exist=0
if command -v docker >/dev/null 2>&1; then
    project="$(basename "$(pwd)")"
    if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -qE "^(cryptopay|${project})_postgres-data$"; then
        volumes_exist=1
    fi
fi

if [ -z "$(get APP_KEY)" ]; then
    set_kv APP_KEY "base64:$(openssl rand -base64 32)"; note "APP_KEY"
fi

case "$(get DB_PASSWORD)" in
    ''|secret|password|postgres)
        newpw="$(rand_hex 24)"
        if [ "$volumes_exist" -eq 1 ]; then
            # База уже инициализирована старым паролем: меняем его прямо в Postgres
            # (внутри контейнера psql ходит по unix-сокету без пароля), затем пишем в .env.
            echo "  info    DB_PASSWORD: том postgres-data уже существует — меняю пароль в работающей БД"
            docker compose up -d postgres >/dev/null 2>&1 || true
            i=0
            until docker compose exec -T postgres pg_isready -U "$(get DB_USERNAME)" -d "$(get DB_DATABASE)" >/dev/null 2>&1; do
                i=$((i + 1)); [ "$i" -lt 30 ] || break; sleep 1
            done
            if docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$(get DB_USERNAME)" -d "$(get DB_DATABASE)" \
                -c "ALTER USER \"$(get DB_USERNAME)\" PASSWORD '$newpw';" >/dev/null 2>&1; then
                set_kv DB_PASSWORD "$newpw"; note "DB_PASSWORD (обновлён и в БД)"
            else
                echo "  ERROR   DB_PASSWORD: не удалось сменить пароль в Postgres. Вручную:" >&2
                echo "          docker compose exec postgres psql -U $(get DB_USERNAME) -d $(get DB_DATABASE) -c \"ALTER USER $(get DB_USERNAME) PASSWORD '<новый>';\"" >&2
                echo "          затем DB_PASSWORD=<новый> в .env. Либо, если данных ещё нет: docker compose down -v && make secrets-prod" >&2
            fi
        else
            set_kv DB_PASSWORD "$newpw"; note "DB_PASSWORD"
        fi ;;
esac

if [ -z "$(get REDIS_PASSWORD)" ]; then
    # requirepass читается при старте redis: make up пересоздаст redis и клиентов с новым паролем.
    set_kv REDIS_PASSWORD "$(rand_hex 24)"; note "REDIS_PASSWORD"
fi

case "$(get INTERNAL_API_TOKEN)" in
    ''|change-me*) set_kv INTERNAL_API_TOKEN "$(rand_hex 32)"; note "INTERNAL_API_TOKEN" ;;
esac

admin_generated=""
case "$(get ADMIN_PASSWORD)" in
    ''|password|admin|secret)
        admin_generated="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"
        set_kv ADMIN_PASSWORD "$admin_generated"; note "ADMIN_PASSWORD" ;;
esac

if [ "$PROD" -eq 1 ]; then
    [ "$(get APP_ENV)" = "production" ] || { set_kv APP_ENV production; note "APP_ENV=production"; }
    [ "$(get APP_DEBUG)" = "false" ] || { set_kv APP_DEBUG false; note "APP_DEBUG=false"; }
    [ "$(get SIMULATION_ENABLED)" = "false" ] || { set_kv SIMULATION_ENABLED false; note "SIMULATION_ENABLED=false"; }
    [ "$(get WEBHOOK_ALLOW_PRIVATE)" = "false" ] || { set_kv WEBHOOK_ALLOW_PRIVATE false; note "WEBHOOK_ALLOW_PRIVATE=false"; }
    case "$(get APP_URL)" in
        ''|http://localhost*) echo "  todo    APP_URL: укажите внешний адрес (https://pay.example.com или http://IP:8095)" >&2 ;;
    esac
fi

echo "init-env: изменено значений: $changed ($ENV_FILE)"
if [ -n "$admin_generated" ]; then
    echo "init-env: пароль администратора ($(get ADMIN_EMAIL)): $admin_generated  — сохраните, он больше не будет показан"
fi
