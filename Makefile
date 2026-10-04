.DEFAULT_GOAL := help

COMPOSE := docker compose

.PHONY: help start start-build test-db models check stop restart ps logs logs-app logs-queue logs-worker shell artisan migrate test test-filter build worker-test

help:
	@echo "CreatorSMP4 development commands:"
	@echo "  make start                  Build, start and verify the full stack (test DB, models, GPU)"
	@echo "  make start-build            Alias for make start"
	@echo "  make check                  Verify services, database, worker and GPU"
	@echo "  make test-db                Create the test database if it is missing"
	@echo "  make models                 Download the Whisper and event models into the cache"
	@echo "  make stop                   Stop and remove containers"
	@echo "  make restart                Restart all services"
	@echo "  make ps                     Show service status"
	@echo "  make logs                   Follow all service logs"
	@echo "  make logs-app               Follow Laravel/Vite logs"
	@echo "  make logs-queue             Follow Laravel queue logs"
	@echo "  make logs-worker            Follow Python worker logs"
	@echo "  make shell                  Open a shell in the app container"
	@echo "  make artisan CMD='...'      Run an Artisan command"
	@echo "  make migrate                Run pending migrations"
	@echo "  make test                   Run the Laravel test suite"
	@echo "  make test-filter FILTER=... Run selected Laravel tests"
	@echo "  make build                  Build frontend assets"
	@echo "  make worker-test            Run Python worker tests"

# Rebuilds only what changed (Docker layer cache), so requirement changes are never missed.
start:
	$(COMPOSE) up -d --build --wait --wait-timeout 300
	@$(MAKE) --no-print-directory test-db
	@$(MAKE) --no-print-directory models
	@$(MAKE) --no-print-directory check

start-build: start

test-db:
	@$(COMPOSE) exec -T postgres psql -U creatorsmp4 -d creatorsmp4 -tAc "SELECT 1 FROM pg_database WHERE datname='creatorsmp4_test'" | grep -q 1 \
		|| $(COMPOSE) exec -T postgres psql -U creatorsmp4 -d creatorsmp4 -c "CREATE DATABASE creatorsmp4_test OWNER creatorsmp4"

models:
	$(COMPOSE) exec -T worker python3 /worker/prefetch_models.py

check:
	@./scripts/check.sh

stop:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=100

logs-app:
	$(COMPOSE) logs -f --tail=100 app

logs-queue:
	$(COMPOSE) logs -f --tail=100 queue

logs-worker:
	$(COMPOSE) logs -f --tail=100 worker

shell:
	$(COMPOSE) exec app sh

artisan:
	$(COMPOSE) exec -T app php artisan $(CMD)

migrate:
	$(COMPOSE) exec -T app php artisan migrate --force

test:
	$(COMPOSE) exec -T app php artisan test

test-filter:
	$(COMPOSE) exec -T app php artisan test $(FILTER)

build:
	$(COMPOSE) exec -T app npm run build

worker-test:
	$(COMPOSE) exec -T worker python3 -m unittest discover -s /worker -p "test_*.py"
