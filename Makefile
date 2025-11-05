.PHONY: help build up down restart logs shell composer install db-create db-migrate db-reset collect-data

# Colors for output
BLUE := \033[0;34m
GREEN := \033[0;32m
YELLOW := \033[0;33m
RED := \033[0;31m
NC := \033[0m # No Color

help: ## Show this help message
	@echo "$(BLUE)PrestaBoost - Available commands:$(NC)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-20s$(NC) %s\n", $$1, $$2}'

# Docker commands
build: ## Build Docker images
	@echo "$(BLUE)Building Docker images...$(NC)"
	cd infra && docker-compose build

up: ## Start Docker containers
	@echo "$(BLUE)Starting Docker containers...$(NC)"
	cd infra && docker-compose up -d
	@echo "$(GREEN)Containers started successfully!$(NC)"
	@echo "Application available at: http://localhost:8080"

down: ## Stop Docker containers
	@echo "$(BLUE)Stopping Docker containers...$(NC)"
	cd infra && docker-compose down

restart: down up ## Restart Docker containers

logs: ## Show Docker logs
	cd infra && docker-compose logs -f

logs-php: ## Show PHP container logs
	cd infra && docker-compose logs -f php

logs-nginx: ## Show Nginx container logs
	cd infra && docker-compose logs -f nginx

# Container shell access
shell: ## Access PHP container shell
	@echo "$(BLUE)Accessing PHP container...$(NC)"
	cd infra && docker-compose exec php bash

shell-postgres: ## Access PostgreSQL container shell
	@echo "$(BLUE)Accessing PostgreSQL container...$(NC)"
	cd infra && docker-compose exec postgres psql -U prestaboost -d prestaboost_db

# Composer commands
composer: ## Run composer install
	@echo "$(BLUE)Running composer install...$(NC)"
	cd infra && docker-compose exec php composer install

composer-update: ## Run composer update
	@echo "$(BLUE)Running composer update...$(NC)"
	cd infra && docker-compose exec php composer update

# Installation
install: build up composer db-create db-migrate ## Full installation
	@echo "$(GREEN)Installation completed!$(NC)"
	@echo "$(YELLOW)Next steps:$(NC)"
	@echo "  1. Generate JWT keys: make jwt-keys"
	@echo "  2. Create a user: make console CMD='app:create-user'"
	@echo "  3. Create a boutique via API"
	@echo "  4. Run data collection: make collect-data"

# Database commands
db-create: ## Create database
	@echo "$(BLUE)Creating database...$(NC)"
	cd infra && docker-compose exec php bin/console doctrine:database:create --if-not-exists

db-migrate: ## Run migrations
	@echo "$(BLUE)Running migrations...$(NC)"
	cd infra && docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction

db-diff: ## Generate migration from entities
	@echo "$(BLUE)Generating migration...$(NC)"
	cd infra && docker-compose exec php bin/console doctrine:migrations:diff

db-reset: ## Reset database (drop, create, migrate)
	@echo "$(RED)Resetting database...$(NC)"
	cd infra && docker-compose exec php bin/console doctrine:database:drop --force --if-exists
	cd infra && docker-compose exec php bin/console doctrine:database:create
	cd infra && docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)Database reset completed!$(NC)"

# Console commands
console: ## Run Symfony console command (usage: make console CMD='your:command')
	cd infra && docker-compose exec php bin/console $(CMD)

collect-data: ## Collect PrestaShop data for all boutiques
	@echo "$(BLUE)Collecting PrestaShop data...$(NC)"
	cd infra && docker-compose exec php bin/console app:collect-prestashop-data --all

collect-boutique: ## Collect data for specific boutique (usage: make collect-boutique ID=1)
	@echo "$(BLUE)Collecting data for boutique $(ID)...$(NC)"
	cd infra && docker-compose exec php bin/console app:collect-prestashop-data --boutique=$(ID)

# JWT keys
jwt-keys: ## Generate JWT keys
	@echo "$(BLUE)Generating JWT keys...$(NC)"
	mkdir -p config/jwt
	cd infra && docker-compose exec php sh -c "openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:change_me"
	cd infra && docker-compose exec php sh -c "openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:change_me"
	@echo "$(GREEN)JWT keys generated!$(NC)"

# Cache
cache-clear: ## Clear Symfony cache
	@echo "$(BLUE)Clearing cache...$(NC)"
	cd infra && docker-compose exec php bin/console cache:clear

# Production deployment
prod-up: ## Start production stack with Traefik
	@echo "$(BLUE)Starting production stack...$(NC)"
	cd infra && docker-compose -f docker-compose.prod.yml up -d
	@echo "$(GREEN)Production stack started!$(NC)"

prod-down: ## Stop production stack
	@echo "$(BLUE)Stopping production stack...$(NC)"
	cd infra && docker-compose -f docker-compose.prod.yml down

prod-logs: ## Show production logs
	cd infra && docker-compose -f docker-compose.prod.yml logs -f

# Status
status: ## Show container status
	@echo "$(BLUE)Container status:$(NC)"
	cd infra && docker-compose ps
