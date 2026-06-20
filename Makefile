SHELL := /bin/bash
COMPOSE := docker compose
.DEFAULT_GOAL := help

.PHONY: help up down restart reset build rebuild shell logs ps bootstrap install seed new-plugin enable-plugin list-plugins

help: ## Show this help
	@awk 'BEGIN{FS=":.*##"} /^[a-zA-Z_-]+:.*##/{printf "  \033[36m%-13s\033[0m %s\n",$$1,$$2}' $(MAKEFILE_LIST)

up: ## Start the stack on a free port (default 34728+) and print the URL
	@bash scripts/pick-port.sh >/dev/null
	@$(COMPOSE) up -d
	@p=$$(grep -E '^DZ_PORT=' .env | cut -d= -f2); echo; echo "  Discuz: http://localhost:$$p   (first boot: give the DB ~20-40s)"; echo

down: ## Stop and remove the stack (DB is ephemeral -> wiped)
	@$(COMPOSE) down

restart: ## Restart the stack (DB re-seeded from ./seed)
	@$(COMPOSE) down
	@$(MAKE) --no-print-directory up

reset: ## Recreate everything from scratch
	@$(COMPOSE) down -v --remove-orphans
	@$(MAKE) --no-print-directory up

build: ## Build the web image
	@$(COMPOSE) build

rebuild: ## Rebuild the web image from scratch
	@$(COMPOSE) build --no-cache

shell: ## Open a shell in the web container
	@$(COMPOSE) exec web bash

logs: ## Follow container logs
	@$(COMPOSE) logs -f

ps: ## Show container status
	@$(COMPOSE) ps

bootstrap: ## First-time setup: start the stack (installer mode if no seed yet)
	@bash scripts/pick-port.sh >/dev/null
	@$(COMPOSE) up -d
	@p=$$(grep -E '^DZ_PORT=' .env | cut -d= -f2); echo; \
	 if [ -f seed/config/config_global.php ]; then \
	   echo "  Already seeded (turnkey). Forum: http://localhost:$$p   admin: admin/admin888"; \
	   echo "  (No install needed. To rebuild the seed: rm -rf seed/db/* seed/config/* && make reset && make install && make seed)"; \
	 else \
	   echo "  Installer: http://localhost:$$p/install/   (or run: make install)"; \
	 fi; echo

install: ## Auto-run the Discuz installer once (no browser needed)
	@bash scripts/auto-install.sh

seed: ## Snapshot the running install into ./seed (turnkey baseline)
	@bash scripts/make-seed.sh

new-plugin: ## Scaffold a plugin:  make new-plugin id=<id>
	@bash scripts/new-plugin.sh "$(id)"

enable-plugin: ## Register+enable a plugin:  make enable-plugin id=<id>
	@bash scripts/enable-plugin.sh "$(id)"

list-plugins: ## Regenerate the Plugins table in README.md
	@bash scripts/list-plugins.sh
