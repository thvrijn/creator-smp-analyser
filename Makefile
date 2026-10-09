.DEFAULT_GOAL := help

COMPOSE := docker compose

ifeq ($(shell uname -s),Darwin)
# macOS: Docker cannot reach the Apple GPU, so the worker runs natively (MLX on Metal) and
# docker-compose.mac.yml leaves it out of Docker. Both exports also reach scripts/check.sh.
export COMPOSE_FILE := docker-compose.yml:docker-compose.mac.yml
export WORKER_PY := $(CURDIR)/scripts/worker-mac.sh
WORKER_DIR := $(CURDIR)/worker
WORKER_LOG := storage/logs/worker.log
# The [w] keeps pkill/pgrep from matching the recipe's own shell.
WORKER_MATCH := $(CURDIR)/[w]orker/entrypoint.py
# How the app container reaches the local worker (scripts/check.sh).
export LOCAL_WORKER_URL := http://host.docker.internal:8001
else
export WORKER_PY := $(COMPOSE) exec -T worker python3
WORKER_DIR := /worker
export LOCAL_WORKER_URL := http://worker:8001
endif

# Only the worker, for a machine that works for the app elsewhere (docker-compose.worker.yml).
REMOTE_WORKER := $(COMPOSE) -f docker-compose.worker.yml

.PHONY: idle help start start-build test-db models check stop restart ps logs logs-app logs-queue logs-worker shell artisan user migrate test test-filter build worker-test prompt-eval worker-up worker-down worker-restart worker-token remote-worker remote-worker-stop remote-worker-logs remote-worker-check

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
	@echo "  make user                   Create a login account (or set a new password)"
	@echo "  make test                   Run the Laravel test suite"
	@echo "  make test-filter FILTER=... Run selected Laravel tests"
	@echo "  make build                  Build frontend assets"
	@echo "  make worker-test            Run Python worker tests"
	@echo "  make prompt-eval            Score the event prompt on synthetic SMP chunks (uses the GPU)"
	@echo ""
	@echo "Remote worker (a laptop/pc/Mac working for the app on another machine, settings in worker/.env):"
	@echo "  make remote-worker          Build, download the models, start the worker and check it checked in"
	@echo "  make remote-worker-stop     Stop the worker (it signs off with the app)"
	@echo "  make remote-worker-logs     Follow the worker's log"

# Rebuilds only what changed (Docker layer cache), so requirement changes are never missed.
start: idle .env worker-token
	mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
	$(COMPOSE) up -d --build --wait --wait-timeout 300
	@$(MAKE) --no-print-directory worker-up
	@$(MAKE) --no-print-directory test-db
	@$(MAKE) --no-print-directory models
	@$(MAKE) --no-print-directory check

start-build: start

# Fresh checkout: a .env with its own APP_KEY.
.env:
	sed "s|^APP_KEY=$$|APP_KEY=base64:$$(openssl rand -base64 32)|" .env.example > $@

# The shared secret between the app and its workers; generated once, kept in .env.
worker-token: .env
	@grep -q '^WORKER_TOKEN=.' .env || { \
		token=$$(openssl rand -hex 32); \
		if grep -q '^WORKER_TOKEN=' .env; then sed -i.bak "s|^WORKER_TOKEN=.*|WORKER_TOKEN=$$token|" .env && rm -f .env.bak; else printf '\nWORKER_TOKEN=%s\n' "$$token" >> .env; fi; \
		echo "Generated WORKER_TOKEN in .env (a remote worker needs the same token in its worker/.env)."; }

test-db:
	@$(COMPOSE) exec -T postgres psql -U creatorsmp4 -d creatorsmp4 -tAc "SELECT 1 FROM pg_database WHERE datname='creatorsmp4_test'" | grep -q 1 \
		|| $(COMPOSE) exec -T postgres psql -U creatorsmp4 -d creatorsmp4 -c "CREATE DATABASE creatorsmp4_test OWNER creatorsmp4"

models:
	$(WORKER_PY) $(WORKER_DIR)/prefetch_models.py

check:
	@./scripts/check.sh

# A restart kills a running job and leaves its stream on "processing", so these refuse while one runs. FORCE=1 goes ahead.
idle:
	@running=$$($(COMPOSE) exec -T postgres psql -U creatorsmp4 -d creatorsmp4 -tAc "SELECT string_agg(id::text, ', ') FROM streams WHERE 'processing' IN (transcription_status, event_extraction_status, video_download_status)" 2>/dev/null); \
	if [ -n "$$running" ] && [ -z "$(FORCE)" ]; then echo "A job is running for stream(s) $$running; restarting would kill it. Wait for it, or use FORCE=1."; exit 1; fi

stop: idle
	$(COMPOSE) down
	@$(MAKE) --no-print-directory worker-down

restart: idle
	$(COMPOSE) restart
	@$(MAKE) --no-print-directory worker-up

ifeq ($(shell uname -s),Darwin)
# Installs ffmpeg and the worker venv; reruns only when requirements-mac.txt changes. uv brings its
# own standalone Python: Homebrew's python@3.12 can need a newer system libexpat than macOS has.
worker/.venv/installed: worker/requirements-mac.txt
	brew list ffmpeg uv >/dev/null 2>&1 || brew install ffmpeg uv
	uv venv --allow-existing --python 3.12 worker/.venv
	uv pip install --python worker/.venv -r worker/requirements-mac.txt
	touch $@

worker-up: idle worker/.venv/installed worker-down
	@mkdir -p storage/logs
	nohup $(WORKER_PY) $(WORKER_DIR)/entrypoint.py >> $(WORKER_LOG) 2>&1 &

worker-down:
	@pkill -f "$(WORKER_MATCH)" && while pgrep -f "$(WORKER_MATCH)" >/dev/null; do sleep 0.2; done; true

worker-restart: worker-up

logs-worker:
	tail -n 100 -f $(WORKER_LOG)
else
# The worker is a compose service here, so up/down/restart already cover it.
worker-up worker-down: ;

worker-restart: idle
	$(COMPOSE) restart worker

logs-worker:
	$(COMPOSE) logs -f --tail=100 worker
endif

# A remote worker's own settings; the first run creates them from the example and stops so you can fill them in.
worker/.env:
	cp worker/.env.example $@
	@echo "Created worker/.env: fill in WORKER_NAME, WORKER_PUBLIC_URL, WORKER_APP_URL and WORKER_TOKEN, then run make remote-worker again."
	@exit 1

ifeq ($(shell uname -s),Darwin)
remote-worker: worker/.env worker/.venv/installed
	$(WORKER_PY) $(WORKER_DIR)/prefetch_models.py
	@$(MAKE) --no-print-directory worker-up
	@$(MAKE) --no-print-directory remote-worker-check

remote-worker-stop: worker-down

remote-worker-logs: logs-worker
else
remote-worker: worker/.env
	$(REMOTE_WORKER) up -d --build
	$(REMOTE_WORKER) exec -T worker python3 /worker/prefetch_models.py
	@$(MAKE) --no-print-directory remote-worker-check

remote-worker-stop:
	$(REMOTE_WORKER) down

remote-worker-logs:
	$(REMOTE_WORKER) logs -f --tail=100 worker
endif

# The worker answers on its port and has checked in with the app (it logs that within a few seconds).
remote-worker-check:
	@for i in $$(seq 1 30); do curl -fsS http://localhost:$${REMOTE_WORKER_PORT:-8001}/health >/dev/null 2>&1 && break; sleep 1; done; \
	curl -fsS http://localhost:$${REMOTE_WORKER_PORT:-8001}/health || { echo "FAIL: the worker does not answer on port $${REMOTE_WORKER_PORT:-8001} (see: make remote-worker-logs)"; exit 1; }; echo; \
	url=$$(sed -n 's/^WORKER_PUBLIC_URL=//p' worker/.env | tail -1 | tr -d "\"'"); \
	[ -z "$$url" ] || curl -fsS -m 5 -o /dev/null "$$url/health" || { echo "FAIL: the worker does not answer on WORKER_PUBLIC_URL $$url, where the app connects (check it in worker/.env, and WORKER_HOST if set)"; exit 1; }; \
	for i in $$(seq 1 20); do \
		log=$$( { [ "$$(uname -s)" = Darwin ] && tail -n 50 $(WORKER_LOG) || $(REMOTE_WORKER) logs --tail=50 worker; } 2>/dev/null | grep 'registration:' | tail -1); \
		case "$$log" in *"checked in"*) echo "ok: $${log#*registration: }"; exit 0;; esac; sleep 1; \
	done; echo "FAIL: the worker did not check in with the app: $${log#*registration: } (check WORKER_APP_URL and WORKER_TOKEN in worker/.env)"; exit 1

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=100

logs-app:
	$(COMPOSE) logs -f --tail=100 app

logs-queue:
	$(COMPOSE) logs -f --tail=100 queue

shell:
	$(COMPOSE) exec app sh

artisan:
	$(COMPOSE) exec -T app php artisan $(CMD)

# Interactive (asks for the password without echoing it), so no -T.
user:
	$(COMPOSE) exec app php artisan user:create

migrate:
	$(COMPOSE) exec -T app php artisan migrate --force

test:
	$(COMPOSE) exec -T app php artisan test

test-filter:
	$(COMPOSE) exec -T app php artisan test $(FILTER)

build:
	$(COMPOSE) exec -T app npm run build

worker-test:
	$(WORKER_PY) -m unittest discover -s $(WORKER_DIR) -p "test_*.py"

prompt-eval:
	@$(MAKE) --no-print-directory worker-restart
	$(WORKER_PY) -W ignore $(WORKER_DIR)/prompt_eval.py
