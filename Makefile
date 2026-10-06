# Development environment of calisero/calisero-symfony: everything runs in Docker containers
# (automation/local/docker-compose.yml), nothing is installed on the host.

COMPOSE = docker compose -f automation/local/docker-compose.yml --env-file automation/local/.env
PHP = $(COMPOSE) exec php
# The PHP and Symfony versions `make test-matrix` runs the tests on, as CI does
# (Symfony 8 requires PHP 8.4)
MATRIX = 8.2:6.4 8.2:7.4 8.3:6.4 8.3:7.4 8.4:6.4 8.4:7.4 8.4:8 8.5:7.4 8.5:8

.DEFAULT_GOAL := help

.PHONY: help env up down restart shell install update test test-coverage test-matrix test-lowest \
	lint format stan qa audit examples console sms serve clean

help: ## List the available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

env: ## Create automation/local/.env from .env.dist (once)
	@test -f automation/local/.env || cp automation/local/.env.dist automation/local/.env

up: env ## Build and start the development container
	$(COMPOSE) up -d --build

down: ## Stop the development container
	$(COMPOSE) down

restart: down up ## Restart the development container

shell: ## Open a shell in the development container
	$(PHP) sh

install: ## Install the dependencies
	$(PHP) composer install

update: ## Update the dependencies
	$(PHP) composer update

test: ## Run the test suite
	$(PHP) composer test

test-coverage: ## Run the test suite with coverage (report in coverage/)
	$(PHP) composer test-coverage

test-matrix: env ## Run the test suite on every supported PHP and Symfony version
	@for pair in $(MATRIX); do \
		php=$${pair%%:*}; symfony=$${pair##*:}; \
		echo "=== PHP $$php, Symfony $$symfony ==="; \
		$(COMPOSE) --profile matrix run --rm -e SYMFONY_REQUIRE="$$symfony.*" php-$$php \
			sh automation/local/isolated.sh composer test || exit 1; \
	done

test-lowest: env ## Run the test suite with the lowest dependencies, on PHP 8.2
	$(COMPOSE) --profile matrix run --rm -e COMPOSER_UPDATE_FLAGS=--prefer-lowest php-8.2 \
		sh automation/local/isolated.sh composer test

lint: ## Check the code style (PHP-CS-Fixer)
	$(PHP) composer cs:check

format: ## Fix the code style (PHP-CS-Fixer)
	$(PHP) composer cs:fix

stan: ## Run the static analysis (PHPStan, level 9)
	$(PHP) composer stan

qa: ## Validate composer.json, check the code style, analyse and test, as CI does
	$(PHP) composer qa

audit: ## Audit the dependencies
	$(PHP) composer audit

examples: ## Check the syntax of the examples
	$(PHP) sh -c 'for file in examples/*.php; do php -l "$$file" || exit 1; done'

console: ## Run a command of the demo application on the real API: make console args="calisero:account"
	$(PHP) php tests/App/bin/console $(args)

sms: ## Send a test SMS to the CALISERO_TEST_PHONE of .env: make sms text="Hello"
	$(PHP) sh -c 'php tests/App/bin/console calisero:sms:test "$$CALISERO_TEST_PHONE" --text="$(or $(text),Hello from Calisero)"'

serve: ## Serve the demo application (its webhook) on the host's WEBHOOK_LOCAL_PORT of .env, 8096
	$(PHP) php -S 0.0.0.0:8080 -t tests/App/public tests/App/public/index.php

clean: ## Remove vendor/, the caches, the containers and their volumes
	$(COMPOSE) run --rm --no-deps php rm -rf vendor composer.lock build coverage .phpunit.cache .php-cs-fixer.cache
	$(COMPOSE) --profile matrix down --rmi local --volumes --remove-orphans
