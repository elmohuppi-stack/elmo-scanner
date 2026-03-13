SHELL := /bin/bash

PROJECT_ROOT := $(CURDIR)
BACKEND_DIR := $(PROJECT_ROOT)/backend
FRONTEND_DIR := $(PROJECT_ROOT)/frontend

.PHONY: help
help:
	@echo "Available targets:"
	@echo "  make setup            - Install deps, create env, migrate, seed"
	@echo "  make db-up            - Start PostgreSQL via Docker"
	@echo "  make db-down          - Stop PostgreSQL via Docker"
	@echo "  make db-reset         - Stop DB and remove volume (destructive)"
	@echo "  make backend-install  - Install backend composer dependencies"
	@echo "  make frontend-install - Install frontend npm dependencies"
	@echo "  make keygen           - Generate Laravel app key"
	@echo "  make migrate          - Run Laravel migrations"
	@echo "  make seed             - Seed predefined RSS feeds"
	@echo "  make fetch            - Run one RSS fetch pass"
	@echo "  make dev              - Start backend and frontend together"
	@echo "  make backend-dev      - Start Laravel API on :8000"
	@echo "  make frontend-dev     - Start Vue dev server on :5173"
	@echo "  make test             - Run Laravel tests"

.PHONY: db-up
db-up:
	docker compose up -d

.PHONY: db-down
db-down:
	docker compose down

.PHONY: db-reset
db-reset:
	docker compose down -v

.PHONY: backend-install
backend-install:
	cd "$(BACKEND_DIR)" && composer install

.PHONY: frontend-install
frontend-install:
	cd "$(FRONTEND_DIR)" && npm install

.PHONY: env
env:
	cd "$(BACKEND_DIR)" && [ -f .env ] || cp .env.example .env

.PHONY: keygen
keygen: env
	cd "$(BACKEND_DIR)" && php artisan key:generate --force

.PHONY: migrate
migrate:
	cd "$(BACKEND_DIR)" && php artisan migrate --force

.PHONY: seed
seed:
	cd "$(BACKEND_DIR)" && php artisan db:seed --force

.PHONY: fetch
fetch:
	cd "$(BACKEND_DIR)" && php artisan feeds:fetch --limit=100

.PHONY: setup
setup: db-up backend-install frontend-install keygen migrate seed
	@echo "Local setup completed."

.PHONY: dev
dev:
	@set -e; \
	cleanup() { \
		for pid in $$(jobs -p); do kill $$pid 2>/dev/null || true; done; \
	}; \
	trap cleanup INT TERM EXIT; \
	(cd "$(BACKEND_DIR)" && php artisan serve --host=127.0.0.1 --port=8000) & \
	(cd "$(FRONTEND_DIR)" && npm run dev -- --host 127.0.0.1 --port 5173) & \
	wait

.PHONY: backend-dev
backend-dev:
	cd "$(BACKEND_DIR)" && php artisan serve --host=127.0.0.1 --port=8000

.PHONY: frontend-dev
frontend-dev:
	cd "$(FRONTEND_DIR)" && npm run dev

.PHONY: test
test:
	cd "$(BACKEND_DIR)" && php artisan test
