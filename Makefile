.PHONY: up down build logs keys ps restart shell test check-env secrets secrets-prod

check-env: ## проверить .env на плейсхолдеры (в APP_ENV != local падает)
	@./scripts/check-env.sh .env

secrets: ## заполнить пустые/дефолтные секреты в .env (APP_KEY, пароли, internal token)
	@./scripts/init-env.sh .env

secrets-prod: ## то же + APP_ENV=production, APP_DEBUG/SIMULATION/WEBHOOK_ALLOW_PRIVATE=false
	@./scripts/init-env.sh .env --production

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
