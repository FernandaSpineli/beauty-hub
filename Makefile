DEV := docker compose run --rm dev

.PHONY: help up down logs shell install test cover lint fmt docs seed

help: ## Show available targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-10s %s\n", $$1, $$2}'

up: ## Build and start the stack (app, postgres, redis, mailpit)
	docker compose up -d --build --wait

down: ## Stop the stack
	docker compose down

logs: ## Follow app logs
	docker compose logs -f app

shell: ## Shell in the development container
	$(DEV) sh

install: ## Install Composer dependencies into vendor/
	$(DEV) composer install

test: ## Run the test suite
	$(DEV) vendor/bin/pest --parallel

cover: ## Run tests with a coverage summary
	$(DEV) vendor/bin/pest --coverage

lint: ## Check formatting and run static analysis
	$(DEV) composer lint

fmt: ## Format code with Pint
	$(DEV) vendor/bin/pint

docs: ## Regenerate docs/openapi.json from the code (Scramble)
	$(DEV) php artisan scramble:export --path=docs/openapi.json

seed: ## Create the two demo stores (bella, glow)
	docker compose run --rm migrate php artisan demo:seed
