#!/bin/sh
# Переводит .env в режим HTTPS для домена: DOMAIN, ACME_EMAIL, APP_URL=https://…,
# APP_BIND=127.0.0.1 (nginx только на localhost, снаружи — caddy :80/:443) и
# COMPOSE_PROFILES=tls, чтобы обычные `make up` / `make pull-up` поднимали caddy.
#
#   ./scripts/set-domain.sh <домен> <e-mail для Let's Encrypt> [.env]
set -eu

DOMAIN="${1:-}"
EMAIL="${2:-}"
ENV_FILE="${3:-.env}"

[ -n "$DOMAIN" ] && [ -n "$EMAIL" ] || {
    echo "usage: $0 <domain> <acme-email> [.env]" >&2
    echo "   eg: make domain DOMAIN=pay.example.com EMAIL=admin@example.com" >&2
    exit 1
}

case "$DOMAIN" in
    http://*|https://*|*/*) echo "set-domain: домен без схемы и слэшей, например pay.example.com" >&2; exit 1 ;;
esac

[ -f "$ENV_FILE" ] || { cp .env.example "$ENV_FILE"; echo "set-domain: создан $ENV_FILE из .env.example"; }

# set KEY VALUE — заменяет строку KEY=… (в т.ч. закомментированную #KEY=…) или добавляет в конец.
set_kv() {
    if grep -qE "^[[:space:]]*#?[[:space:]]*$1=" "$ENV_FILE"; then
        sed -i.bak "s|^[[:space:]]*#\{0,1\}[[:space:]]*$1=.*|$1=$2|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
    else
        printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
    fi
    echo "  set     $1=$2"
}

set_kv DOMAIN "$DOMAIN"
set_kv ACME_EMAIL "$EMAIL"
set_kv APP_URL "https://$DOMAIN"
set_kv APP_BIND "127.0.0.1"
set_kv COMPOSE_PROFILES "tls"

echo "set-domain: готово. Убедитесь, что A-запись $DOMAIN указывает на этот сервер и открыты порты 80/443,"
echo "            затем: make pull-up (или make up). Сертификат: docker compose logs -f caddy"
