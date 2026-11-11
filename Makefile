.DEFAULT_GOAL := help

COMPOSE ?= docker compose
ARGS ?=
SERVICE ?=

.PHONY: help up down restart ps logs shell build docker-build artisan composer test test-db migrate migrate-status cache-clear

help: ## Показать доступные команды
	@awk 'BEGIN { FS = ":.*## "; print "Команды бэкенда (Docker Compose):\n" } /^[a-zA-Z0-9_-]+:.*## / { printf "  %-18s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

up: ## Собрать и запустить бэкенд и PostgreSQL, дождаться готовности
	$(COMPOSE) up -d --build --wait --remove-orphans

down: ## Остановить окружение, сохранив данные в томах
	$(COMPOSE) down --remove-orphans

restart: ## Пересоздать контейнеры с текущими настройками, сохранив данные
	$(COMPOSE) down --remove-orphans
	$(COMPOSE) up -d --wait

ps: ## Показать состояние контейнеров
	$(COMPOSE) ps

logs: ## Смотреть логи; необязательно SERVICE="app db"
	$(COMPOSE) logs --follow --tail=100 $(SERVICE)

shell: ## Открыть shell в контейнере; по умолчанию app, можно SERVICE=db
	$(COMPOSE) exec $(if $(SERVICE),$(SERVICE),app) sh

build: ## Собрать Docker-образ бэкенда
	$(COMPOSE) build $(SERVICE)

docker-build: build ## Алиас для make build

artisan: ## Выполнить Artisan: make artisan ARGS="route:list"
	$(COMPOSE) exec -T app php artisan $(ARGS)

composer: ## Выполнить Composer: make composer ARGS="show --direct"
	$(COMPOSE) exec -T app composer $(ARGS)

test-db: ## Создать отдельную PostgreSQL-базу тестов, если её ещё нет
	$(COMPOSE) exec -T db sh -c 'psql -v ON_ERROR_STOP=1 -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"' < docker/postgres/create-test-database.sql

test: test-db ## Запустить тесты Laravel; необязательно ARGS="--filter=ApiServiceTest"
	$(COMPOSE) exec -T app php artisan test --compact $(ARGS)

migrate: ## Выполнить миграции Laravel
	$(COMPOSE) exec -T app php artisan migrate --no-interaction $(ARGS)

migrate-status: ## Показать состояние миграций
	$(COMPOSE) exec -T app php artisan migrate:status --no-interaction

cache-clear: ## Очистить кеши Laravel
	$(COMPOSE) exec -T app php artisan optimize:clear --no-interaction
