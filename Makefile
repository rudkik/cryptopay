.PHONY: up pull-up down build logs keys ps restart shell test check-env secrets secrets-prod domain deploy backup caddy-logs help


check-env: ## проверить .env на плейсхолдеры (в APP_ENV != local падает)
	@./scripts/check-env.sh .env

secrets: ## заполнить пустые/дефолтные секреты в .env (APP_KEY, пароли, internal token)
	@./scripts/init-env.sh .env

secrets-prod: ## то же + APP_ENV=production, APP_DEBUG/SIMULATION/WEBHOOK_ALLOW_PRIVATE=false
	@./scripts/init-env.sh .env --production

domain: ## включить HTTPS-режим: make domain DOMAIN=pay.example.com EMAIL=admin@example.com
	@./scripts/set-domain.sh "$(DOMAIN)" "$(EMAIL)" .env

# Полный цикл обновления на сервере: забрать код, обновить образы (готовые из
# GHCR, если задан IMAGE_PREFIX, иначе сборка на месте), перезапустить, показать
# состояние. Миграции применяет контейнер app при старте. COMPOSE_PROFILES=tls
# из .env подхватывается compose'ом сам, так что caddy обновляется вместе со всеми.
deploy: ## git pull + обновление образов + перезапуск (см. DEPLOY.md)
	git pull --ff-only
	@if grep -qE '^IMAGE_PREFIX=.+' .env; then $(MAKE) pull-up; else $(MAKE) up; fi
	docker compose ps

backup: ## дамп базы в ./backups/cryptopay-<дата>.sql.gz
	@mkdir -p backups
	@docker compose exec -T postgres pg_dump -U "$$(sed -n 's/^DB_USERNAME=//p' .env)" "$$(sed -n 's/^DB_DATABASE=//p' .env)" \
	  | gzip > "backups/cryptopay-$$(date +%F-%H%M).sql.gz" && ls -la backups | tail -1

caddy-logs: ## логи TLS-терминатора (выпуск сертификата)
	docker compose logs -f --tail=100 caddy

help: ## список целей
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

pull-up: ## запуск из готовых образов GHCR без сборки (IMAGE_PREFIX/IMAGE_TAG в .env)
	@[ -f .env ] || cp .env.example .env
	@grep -qE '^APP_KEY=.+' .env || { \
		KEY="base64:$$(openssl rand -base64 32)"; \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$KEY|" .env && rm -f .env.bak; }
	@./scripts/check-env.sh .env
	@grep -qE '^IMAGE_PREFIX=.+' .env || { \
		echo "pull-up: в .env не задан IMAGE_PREFIX (например IMAGE_PREFIX=ghcr.io/rudkik/cryptopay)."; \
		echo "         Образы публикует GitHub Actions после push в main (см. DEPLOY.md 4a). Для сборки на месте: make up"; \
		exit 1; }
	docker compose pull
	docker compose up -d --no-build

up: ## build & start everything
	@[ -f .env ] || cp .env.example .env
	@grep -qE '^APP_KEY=.+' .env || { \
		KEY="base64:$$(openssl rand -base64 32)"; \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$KEY|" .env && rm -f .env.bak; \
		echo "APP_KEY сгенерирован и записан в .env"; }
	@./scripts/check-env.sh .env
	docker compose up -d --build

down:
	docker compose down

build:
	docker compose build

logs:
	docker compose logs -f --tail=100

ps:
	docker compose ps

restart:
	docker compose restart app queue scheduler watcher

shell:
	docker compose exec app sh

keys: ## generate mnemonic + xpubs for the watcher
	docker compose run --rm --no-deps watcher npm run keygen

# Тесты нельзя запускать в работающем контейнере app: боевой образ собран
# `composer install --no-dev` (в нём нет phpunit) и без каталога tests/
# (backend/.dockerignore). Поэтому — одноразовый контейнер: tests/ монтируется
# внутрь, dev-зависимости ставятся на лету.
#
# Переменные ниже дублируют <env> из backend/phpunit.xml намеренно: PHPUnit не
# перезаписывает уже заданные переменные окружения (без force="true"), а compose
# отдаёт контейнеру боевые APP_URL/QUEUE_CONNECTION=redis/CACHE_STORE=redis —
# и тесты падают на них, а не на коде.
#
#   make test                       # весь набор
#   make test ARGS="--filter=Wallet" # часть
test: ## backend-тесты в одноразовом контейнере
	@docker compose run --rm --no-deps \
	  -e APP_ENV=testing -e APP_URL=http://localhost:8080 -e APP_DEBUG=false \
	  -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
	  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
	  -e INTERNAL_API_TOKEN=test-internal-token -e WATCHER_URL=http://watcher.test:3100 \
	  -e SIMULATION_ENABLED=true -e TOKEN_SALE_ENABLED=false \
	  -v "$(CURDIR)/backend/tests:/opt/tests:ro" \
	  --entrypoint sh app -c '\
	    cp -R /opt/tests /var/www/html/tests && mkdir -p /var/www/html/tests/Unit && \
	    touch /var/www/html/.env && \
	    composer install --no-interaction --no-progress --quiet && \
	    php artisan test $(ARGS)'
