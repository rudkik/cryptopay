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

Файл `.env` не исполняет команды: в нём должны стоять готовые значения, а не `$(openssl …)`.
Все секреты заполняет одна команда (уже заданные значения она не трогает):

```bash
make secrets-prod
```

Она генерирует `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `INTERNAL_API_TOKEN`, `ADMIN_PASSWORD`
(пароль администратора печатается один раз — сохраните), выставляет `APP_ENV=production`, `APP_DEBUG=false`,
`SIMULATION_ENABLED=false`, `WEBHOOK_ALLOW_PRIVATE=false`. Запускайте её **до** первого `make up`:
после создания базы пароли БД и Redis сменить одной командой уже нельзя.

Затем откройте `.env` и заполните вручную:

```bash
APP_URL=https://pay.example.com          # внешний адрес; на IP-этапе: http://1.2.3.4:8095
ADMIN_EMAIL=admin@example.com

ETH_RPC_URL=https://...                   # свои ноды или провайдер (публичные из примера годятся для старта)
BSC_RPC_URL=https://...
TRON_API_KEY=<ключ с trongrid.io>

# HTTPS (раздел 6)
DOMAIN=pay.example.com
ACME_EMAIL=admin@example.com
APP_BIND=127.0.0.1
```

`WEBHOOK_ALLOW_PRIVATE=true` нужен только если ваши сервисы принимают вебхуки на приватных адресах.
`EVM_XPUB` / `TRON_XPUB` можно оставить пустыми: кошелёк задаётся через админку (раздел 5).

Проверка (падает, если остались плейсхолдеры или опасные флаги):

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

## 4a. Готовые образы вместо сборки на сервере (рекомендуется)

Сборка на сервере компилирует PHP-расширения и фронтенд; на слабой VPS (1 vCPU, 1–2 ГБ RAM) это занимает
от 30 минут до часа и может упасть по памяти. Вместо этого образы собирает GitHub Actions при каждом push
в `main` (workflow `.github/workflows/images.yml`) и публикует в GHCR:
`ghcr.io/<owner>/cryptopay-app`, `-watcher`, `-nginx` (amd64 и arm64).

Один раз: в GitHub → Packages сделайте три пакета публичными, либо на сервере выполните
`docker login ghcr.io` с токеном `read:packages`.

На сервере в `.env`:

```bash
IMAGE_PREFIX=ghcr.io/<owner>/cryptopay
IMAGE_TAG=latest              # или короткий SHA коммита для фиксации версии
```

и вместо `make up` используйте:

```bash
make pull-up                  # docker compose pull + up без сборки, ~1 минута
```

Обновление версии: `git pull && make pull-up`. Если всё же собираете на сервере: добавьте swap
(`fallocate -l 2G /swapfile && mkswap /swapfile && swapon /swapfile`) и запускайте
`COMPOSE_PARALLEL_LIMIT=1 make up`, чтобы образы собирались по очереди.

## 5. Кошелёк: куда приходят деньги

Есть два способа, их можно совмещать. Приватных ключей в системе нет ни в одном из них.

### 5а. Список своих адресов (проще всего)

Админка → **Addresses** → «Add address». Для каждого адреса указываете:

| Поле | Что значит |
|------|-----------|
| Network | сеть адреса: Ethereum, BNB Smart Chain или Tron. Формат проверяется (`0x…` 40 hex для EVM, `T…` 34 символа для Tron) |
| Address | адрес из вашего кошелька (Trust Wallet, Ledger, биржевой депозит и т.п.) |
| Accepts | какие монеты на него принимать: все, что включены на сети, или только выбранные (например USDT) |
| Priority | чем меньше число, тем раньше адрес берётся; при равном приоритете адреса чередуются |
| Enabled | выключенный адрес новым счетам не выдаётся, но история и мониторинг остаются |

Как выбирается адрес для счёта: берётся сеть и монета счёта → из списка отбираются включённые адреса этой
сети, принимающие эту монету → из них первый **свободный** (не занятый другим открытым счётом) по приоритету
и давности использования. Адрес закрепляется за счётом на время его жизни плюс `ADDRESS_LEASE_GRACE_SECONDS`
(по умолчанию 30 минут), после чего снова свободен. Отмена счёта освобождает адрес сразу.

Пока адрес занят, второй счёт на ту же пару сеть/монета получит следующий адрес из списка; если свободных
нет и xpub не задан — API ответит `503 no_free_address`. Поэтому на каждую пару сеть/монета держите столько
адресов, сколько счетов одновременно бывает открыто (для старта хватит 2–3 на сеть). Занятость видна в колонке
Status (`free` / `busy` со ссылкой на счёт).

Сеть и монета появляются в выборе на странице оплаты, как только для них есть хотя бы один включённый адрес
(или xpub из 5б).

### 5б. xpub: новый адрес на каждый счёт (необязательно)

Сервис хранит только расширенные публичные ключи (xpub). Деньги приходят на адреса, выведенные из вашего
мнемоника, и остаются под вашим контролем. Если задан и список адресов, и xpub, список используется первым,
а xpub — когда все адреса списка заняты.

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
make domain DOMAIN=pay.example.com EMAIL=admin@example.com
# записывает в .env: DOMAIN, ACME_EMAIL, APP_URL=https://…, APP_BIND=127.0.0.1, COMPOSE_PROFILES=tls
make pull-up                      # или make up — с профилем tls поднимается и caddy
make caddy-logs                   # выпуск сертификата Let's Encrypt
```

Caddy слушает 80/443, сам выпускает и продлевает сертификат, включает HTTP→HTTPS и HSTS. nginx при этом
доступен только с localhost. `make check-env` следит, чтобы профиль `tls`, `DOMAIN`, `ACME_EMAIL` и
`https://` в `APP_URL` были заданы согласованно. Переезд с IP на домен позже описан в README, раздел
«Переезд с IP на домен и HTTPS».

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
make deploy                  # git pull + make pull-up (или make up, если IMAGE_PREFIX не задан) + ps
```

Миграции применяет контейнер `app` при старте. Все цели: `make help`.

Данные живут в томах и при обновлении сохраняются: `postgres-data`, `redis-data`, `app-storage`,
`watcher-data` (состояние сканера), `caddy-data` (сертификаты).

**Бэкапы**

```bash
# база (ежедневно по cron): make backup → ./backups/cryptopay-<дата>.sql.gz, либо вручную:
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
| Счёт не создаётся, ошибка `wallet_not_configured` | для сети нет ни адреса в списке, ни xpub: раздел 5 |
| Счёт не создаётся, ошибка `no_free_address` | все адреса списка для этой сети/монеты заняты открытыми счетами: добавьте адреса (раздел 5а) или задайте xpub как резерв (5б) |
| Сертификат не выпускается (`make caddy-logs`) | A-запись домена не указывает на сервер, порты 80/443 закрыты, или `DOMAIN` в `.env` не совпадает с доменом |
| Сборка идёт десятки минут / падает по памяти | слабый сервер: используйте готовые образы (`make pull-up`, раздел 4a) или swap + `COMPOSE_PARALLEL_LIMIT=1` |
| `make up` падает на check-env | в `.env` остались значения из примера: `make secrets-prod`, затем проверьте `APP_URL` |
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

## 9. Пошагово: ubaduba.top

Сценарий «чистый Ubuntu-сервер, домен ubaduba.top, готовые образы из GHCR». Команды на сервере под пользователем с sudo.

**Шаг 0. DNS.** У регистратора/в панели DNS создайте A-запись `ubaduba.top → <IP сервера>` (и, если хотите, `www`).
Проверка с любой машины: `dig +short ubaduba.top` должен вернуть IP сервера. Без этого Let's Encrypt не выдаст сертификат.

**Шаг 1. Сервер.**

```bash
sudo apt-get update && sudo apt-get install -y git make openssl ufw
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER && newgrp docker
sudo ufw allow OpenSSH && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw --force enable
# слабый VPS (≤2 ГБ RAM): swap на всякий случай
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
```

**Шаг 2. Код и секреты.**

```bash
sudo mkdir -p /opt/cryptopay && sudo chown $USER /opt/cryptopay
git clone git@github.com:rudkik/cryptopay.git /opt/cryptopay    # или https://github.com/rudkik/cryptopay.git
cd /opt/cryptopay
make secrets-prod          # APP_KEY, пароли БД/Redis, INTERNAL_API_TOKEN, ADMIN_PASSWORD (печатается один раз — сохраните)
make domain DOMAIN=ubaduba.top EMAIL=admin@ubaduba.top
```

Затем в `.env` руками:

```bash
ADMIN_EMAIL=admin@ubaduba.top
IMAGE_PREFIX=ghcr.io/rudkik/cryptopay     # готовые образы (раздел 4a); пакеты в GHCR должны быть public,
IMAGE_TAG=latest                          # иначе перед pull: docker login ghcr.io
TRON_API_KEY=<ключ с trongrid.io>         # бесплатный; без него Tron сканируется с жёсткими лимитами
ETH_RPC_URL=... / BSC_RPC_URL=...         # публичные из примера годятся для старта
```

```bash
make check-env             # должен сказать OK
```

**Шаг 3. Запуск.**

```bash
make pull-up               # скачивает образы и поднимает 8 сервисов (включая caddy)
docker compose ps          # все Up / healthy
make caddy-logs            # ждём "certificate obtained successfully" для ubaduba.top
```

Откройте `https://ubaduba.top/login`: логин `ADMIN_EMAIL`, пароль из вывода `make secrets-prod`.

**Шаг 4. Куда приходят деньги.** Админка → **Addresses** → «Add address»: сеть, ваш адрес, принимаемые монеты
(раздел 5а). Добавьте по 2–3 адреса на каждую сеть, которую хотите принимать; ненужные сети выключите в **Networks**.
После этого сеть/монета появляются на странице оплаты.

**Шаг 5. Подключение вашего сайта.** Раздел 7: сервис → API-ключ → webhook secret → SDK. Документация для
интеграторов: `https://ubaduba.top/docs`, `https://ubaduba.top/swagger`.

**Шаг 6. Эксплуатация.**

```bash
make deploy                # обновление до свежего main
make backup                # дамп базы в ./backups
make logs                  # логи всех сервисов
```

Сохраните вне сервера: `.env` (в нём `APP_KEY`), дампы из `make backup`, и, если используете xpub, мнемоник.
