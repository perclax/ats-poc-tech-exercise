.PHONY: init test analyse lint check smoke down hard-reset

COMPOSE := docker compose
RUN_APP := $(COMPOSE) run --rm app
RUN_TEST := $(COMPOSE) run --rm -e APP_ENV=test -e APP_DEBUG=1 -e MONGODB_DB=ats_test app

init:
	$(COMPOSE) build
	$(COMPOSE) run --rm --no-deps app composer install --no-interaction --prefer-dist
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec -T app php bin/console messenger:setup-transports
	$(COMPOSE) exec -T app php bin/console doctrine:mongodb:schema:update
	$(COMPOSE) exec -T app php bin/console app:applications:seed-demo --if-empty

test:
	$(RUN_TEST) php vendor/bin/phpunit

analyse:
	$(RUN_APP) php vendor/bin/phpstan analyse

lint:
	$(RUN_APP) php vendor/bin/php-cs-fixer check --diff

smoke:
	./tests/Smoke/compose.sh
	$(COMPOSE) exec -T app php bin/console app:probe:mongodb
	$(COMPOSE) exec -T app php bin/console app:probe:rabbitmq
	./tests/Smoke/http.sh
	./tests/Smoke/enrichment.sh
	./tests/Smoke/browse.sh

check: test analyse lint smoke

down:
	$(COMPOSE) down

hard-reset:
	@echo "WARNING: Deleting application data and all Docker volumes for this Compose project."
	$(COMPOSE) down --volumes --remove-orphans
	$(MAKE) init
