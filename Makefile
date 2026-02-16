.PHONY: up down down-v install start bash test test-coverage ci restart

APP := app
DC := docker-compose -f docker/docker-compose.yml

up:
	$(DC) up -d --build

install:
	$(DC) exec $(APP) composer install

start: up install

restart: down up

bash:
	$(DC) exec $(APP) bash


test:
	$(DC) exec $(APP) composer test


test-coverage:
	$(DC) exec $(APP) composer test:coverage

stan:
	$(DC) exec $(APP) composer stan

cs:
	$(DC) exec $(APP) composer cs

cs-fix:
	$(DC) exec $(APP) composer cs:fix

ci:
	$(DC) exec $(APP) composer ci

down:
	$(DC) down

down-v:
	$(DC) down -v
