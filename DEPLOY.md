# CryptoPay — развёртывание в продакшене

Пошаговая инструкция для запуска на собственном сервере. Быстрый локальный старт описан в [README.md](README.md),
модель угроз и чеклист безопасности — в [SECURITY.md](SECURITY.md).

## 1. Требования

| Что | Минимум |
|-----|---------|
| Сервер | Linux x86_64/arm64, 2 vCPU, 4 ГБ RAM, 40 ГБ SSD (Ubuntu 22.04/24.04 или Debian 12) |
| ПО | Docker Engine ≥ 24 и Docker Compose plugin ≥ 2.18, `git`, `make`, `openssl` |
| Сеть | Открытые порты 80 и 443 (для TLS-режима), исходящий доступ к RPC-нодам и TronGrid |
| Домен | A-запись на IP сервера (для HTTPS), например `pay.example.com` |
| Внешние сервисы | RPC для Ethereum и BSC (публичные подходят для старта, для нагрузки — свои/платные), бесплатный `TRON_API_KEY` с trongrid.io |

Установка Docker на Ubuntu:

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER && newgrp docker
sudo apt-get install -y git make
```

## 2. Получение кода

```bash
git clone git@github.com:rudkik/cryptopay.git /opt/cryptopay
cd /opt/cryptopay
cp .env.example .env
chmod 600 .env
```

## 3. Настройка `.env`

Обязательные изменения для продакшена:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pay.example.com          # внешний адрес (на IP-этапе: http://1.2.3.4:8095)
APP_KEY=                                  # оставьте пустым: make up сгенерирует и запишет

SIMULATION_ENABLED=false
TOKEN_SALE_ENABLED=false
WEBHOOK_ALLOW_PRIVATE=false               # true только если ваши сервисы на приватных адресах

DB_PASSWORD=$(openssl rand -hex 24)       # подставьте значения, не команды
REDIS_PASSWORD=$(openssl rand -hex 24)
INTERNAL_API_TOKEN=$(openssl rand -hex 32)
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=<длинный пароль, минимум 12 символов>

ETH_RPC_URL=https://...                   # свои ноды или провайдер
BSC_RPC_URL=https://...
TRON_API_KEY=<ключ с trongrid.io>

# HTTPS (раздел 6)
DOMAIN=pay.example.com
ACME_EMAIL=admin@example.com
APP_BIND=127.0.0.1
```

`EVM_XPUB` / `TRON_XPUB` можно оставить пустыми: кошелёк удобнее задать через админку (раздел 5).

Проверка перед запуском (падает, если остались плейсхолдеры):

```bash
make check-env
```

## 4. Первый запуск

```bash
make up            # сборка образов, генерация APP_KEY, запуск 7 сервисов
docker compose ps  # все сервисы должны быть Up / healthy
docker compose logs -f app   # ждём "[entrypoint] Starting: php-fpm"
```

Контейнер `app` при старте сам выполняет миграции и идемпотентные сиды (сети, контракты, админ).
Демо-сервис и демо-ключ в `APP_ENV=production` получают случайные значения и в логи не печатаются.

Первый вход: `https://pay.example.com/login` (или `http://IP:8095/login`), логин `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
Сразу после входа создайте личных пользователей в «Admin users» и смените пароль бутстрап-админа.

## 5. Кошелёк: куда приходят деньги

Сервис хранит только расширенные публичные ключи (xpub). Деньги приходят на адреса, выведенные из вашего
мнемоника, и остаются под вашим контролем; приватных ключей в системе нет.

Вариант А, свой существующий кошелёк (рекомендуется): экспортируйте account-level xpub
(путь `m/44'/60'/0'` для Ethereum/BSC и `m/44'/195'/0'` для Tron) из Ledger, Trust Wallet или другого BIP-44 кошелька.

Вариант Б, новый кошелёк:

```bash
make keys          # печатает мнемоник, EVM_XPUB, TRON_XPUB и первые адреса
```

Мнемоник запишите офлайн, в сервис он не попадает.

Затем в админке **Wallet** для каждой сети нажмите «Set xpub», вставьте xpub, нажмите «Preview addresses»
и сверьте первые адреса с вашим кошельком, после чего сохраните (для Ethereum и BSC одна галочка применяет
общий xpub). Статус карточек должен стать «Configured from database». Только после этого сети появятся
в выборе на странице оплаты.

## 6. HTTPS и домен

```bash
# в .env: DOMAIN, ACME_EMAIL, APP_BIND=127.0.0.1, APP_URL=https://...
docker compose --profile tls up -d
docker compose logs -f caddy      # выпуск сертификата Let's Encrypt
```

Caddy слушает 80/443, сам выпускает и продлевает сертификат, включает HTTP→HTTPS и HSTS. nginx при этом
доступен только с localhost. Переезд с IP на домен позже описан в README, раздел «Переезд с IP на домен и HTTPS».

Доступ к админке рекомендуется дополнительно ограничить (VPN или allowlist IP в Caddyfile), публичными
должны оставаться только `/pay/*`, `/api/public/*`, `/api/v1/*`, `/docs`, `/swagger`.

## 7. Подключение вашего сервиса

1. Админка → **Services** → «New service»: название, e-mail, `webhook_url` вашего сервиса (https).
2. В карточке сервиса → «API keys» → создать ключ `cp_live_…` (показывается один раз).
3. Там же скопируйте webhook secret (или ротируйте, чтобы увидеть новый).
4. В вашем сервисе: установите SDK (`sdk/php` или `sdk/js`), укажите base URL процессинга, ключ и секрет.
5. Проверьте вручную: создайте счёт через `/swagger` кнопкой Authorize, откройте `payment_url`, дождитесь
   вебхука `invoice.paid` на вашем сервисе (в разделе **Webhooks** видно доставки и ответы).

Документация для интеграторов открыта без входа: `https://pay.example.com/docs` и `/swagger`,
машинно-читаемая спецификация: `/openapi.yaml`.

## 8. Эксплуатация

**Обновление версии**

```bash
cd /opt/cryptopay
git pull
make up                      # пересборка изменившихся образов; миграции применит app при старте
docker compose ps
```

Данные живут в томах и при обновлении сохраняются: `postgres-data`, `redis-data`, `app-storage`,
`watcher-data` (состояние сканера), `caddy-data` (сертификаты).

**Бэкапы**

```bash
# база (ежедневно по cron)
docker compose exec -T postgres pg_dump -U cryptopay cryptopay | gzip > /backup/cryptopay-$(date +%F).sql.gz
# восстановление
gunzip -c /backup/cryptopay-2026-09-05.sql.gz | docker compose exec -T postgres psql -U cryptopay cryptopay
```

Обязательно храните копию `.env` (в нём `APP_KEY`, без него не расшифруются зашифрованные поля) и мнемоник кошелька.

**Мониторинг**

- Дашборд админки: состояние watcher по сетям, отставание в блоках, время последнего сигнала.
- `docker compose logs -f watcher` — сканер; `docker compose logs -f queue` — доставка вебхуков.
- `GET /api/admin/watcher/health` (с админским токеном) для внешнего мониторинга;
  тревога при `healthy=false` или `lag > 50`.
- Раздел **Webhooks**: доставки со статусом `failed` требуют внимания на стороне вашего сервиса.

**Типовые проблемы**

| Симптом | Причина и действие |
|---------|--------------------|
| `/api/internal/*` отвечает 503 | `INTERNAL_API_TOKEN` оставлен плейсхолдером: задайте случайный и перезапустите `app`, `queue`, `scheduler`, `watcher` вместе |
| Счёт не создаётся, ошибка `wallet_not_configured` | не задан xpub для сети: раздел 5 |
| Watcher «Degraded», лаг растёт | лимиты публичного RPC/TronGrid: задайте `TRON_API_KEY`, свои RPC; `docker compose logs watcher` |
| Вебхуки `failed` | ваш сервис отвечает не 2xx, либо адрес приватный при `WEBHOOK_ALLOW_PRIVATE=false`; кнопка Retry в админке |
| После смены `.env` ничего не изменилось | переменные читаются при старте: `docker compose up -d` (пересоздаст изменившиеся контейнеры) |

**Откат**

```bash
git checkout <предыдущий коммит>
make up
```

Миграции обратимы (`docker compose exec app php artisan migrate:rollback --step=1`), но безопаснее
восстановить бэкап базы, снятый перед обновлением.

**Полная остановка**

```bash
docker compose --profile tls down        # контейнеры; данные в томах остаются
docker compose down -v                   # ВНИМАНИЕ: удалит базу, состояние watcher и сертификаты
```
