.PHONY: up down build logs keys ps restart shell test check-env

check-env: ## проверить .env на плейсхолдеры (в APP_ENV != local падает)
	@./scripts/check-env.sh .env

up: ## build & start everything
	@[ -f .env ] || cp .env.example .env
	@./scripts/check-env.sh .env
	@grep -qE '^APP_KEY=.+' .env || { \
		echo "Generating APP_KEY..."; \
		docker compose build app >/dev/null; \
		KEY=$$(docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show | tr -d '\r'); \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$KEY|" .env && rm -f .env.bak; }
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

test:
	docker compose exec app php artisan test
