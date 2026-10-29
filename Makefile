# Greywarden helper commands. Run `make help` for a list.
SHELL := /bin/bash
-include .env
export

DC      := docker compose
PHP     := $(DC) exec php
PHP_T   := $(DC) exec -T php
DB_NAME ?= greywarden
DB_TEST_NAME ?= greywarden_test
BACKUP_DIR ?= backups
# AS= is the import actor (import-devices, import-cloud, sync-cloudflare, suppliers-from-cloud, edge-pull); drop make's built-in AS (the assembler, "as")
ifeq ($(origin AS),default)
AS :=
endif

.DEFAULT_GOAL := help
.PHONY: help env up down build rebuild restart ps logs shell composer yii migrate wait \
        reset-db create-admin seed test test-db test-all smoke hooks leak-check db-shell backup restore deploy attack-status attack-update intel-status intel-update import-devices \
        import-cloud sync-cloudflare suppliers-from-cloud edge-pull edge-status expire-blocks cloudflare-migrate

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

env: ## Create .env from .env.example if missing
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")

up: env ## Build (if needed) and start all containers
	@mkdir -p storage/attachments storage/branding && chmod 750 storage storage/attachments storage/branding
	$(DC) up -d
	@$(MAKE) --no-print-directory wait

down: ## Stop and remove containers (data volume is kept)
	$(DC) down

build: ## Rebuild the PHP image
	$(DC) build php

rebuild: ## Rebuild images without cache and restart
	$(DC) build --no-cache php && $(DC) up -d

restart: ## Restart all containers
	$(DC) restart

ps: ## Show container status
	$(DC) ps

logs: ## Follow container logs (make logs s=php for one service)
	$(DC) logs -f --tail=100 $(s)

wait: ## Wait until PHP dependencies are installed
	@echo -n "Waiting for PHP container"; \
	for i in $$(seq 1 120); do \
	  if $(PHP_T) sh -c 'test -f vendor/autoload.php && flock -n runtime/.composer.lock true' 2>/dev/null; then echo " ready."; exit 0; fi; \
	  echo -n "."; sleep 2; \
	done; echo " timed out (see: make logs s=php)"; exit 1

shell: ## Open a bash shell in the PHP container
	$(PHP) bash

composer: ## Run composer (make composer ARGS="require foo/bar")
	$(PHP) composer $(ARGS)

yii: ## Run a yii console command (make yii ARGS="migrate/history")
	$(PHP) php yii $(ARGS)

migrate: wait ## Apply database migrations
	$(PHP_T) php yii migrate --interactive=0

seed: ## Load demo data (make seed ARGS="--reset=1" to wipe domain data first)
	@if [[ "$${COMPOSE_FILE:-}" == *docker-compose.prod.yml* ]]; then echo "Refusing: demo data in a production checkout" >&2; exit 1; fi
	$(PHP_T) php yii seed/demo --interactive=0 $(ARGS)

create-admin: ## Create an admin from .env (or make create-admin ARGS="jdoe jdoe@example.org 'P@ssw0rd!23'")
	$(PHP) php yii user/create-admin $(ARGS)

import-devices: ## Import a Google Workspace device export: make import-devices FILE=devices.csv AS=jdoe [DRY_RUN=1]
	@test -n "$(FILE)" || (echo "Usage: make import-devices FILE=devices.csv AS=<username> [DRY_RUN=1]" && exit 1)
	$(PHP_T) php yii device/import - $(if $(AS),--user=$(AS)) $(if $(DRY_RUN),--dry-run=1) < "$(FILE)"

import-cloud: ## Import a cloud inventory export: make import-cloud FILE=resources.json PROVIDER=aws|azure|gcp AS=jdoe [ACCOUNT=ACC-0003] [DRY_RUN=1]
	@test -n "$(FILE)" -a -n "$(PROVIDER)" || (echo "Usage: make import-cloud FILE=resources.json PROVIDER=aws|azure|gcp AS=<username> [ACCOUNT=ACC-0003] [DRY_RUN=1]" && exit 1)
	$(PHP_T) php yii cloud/import - --provider=$(PROVIDER) $(if $(ACCOUNT),--account=$(ACCOUNT)) $(if $(AS),--user=$(AS)) $(if $(DRY_RUN),--dry-run=1) < "$(FILE)"

sync-cloudflare: ## Sync Cloudflare zones into the Cloud Inventory (CLOUDFLARE_API_TOKEN, Zone Read): make sync-cloudflare AS=jdoe [DRY_RUN=1]
	$(PHP_T) php yii cloud/sync-cloudflare $(if $(AS),--user=$(AS)) $(if $(DRY_RUN),--dry-run=1)

suppliers-from-cloud: ## Add one supplier per cloud provider in use (accounts are not linked): make suppliers-from-cloud AS=jdoe [DRY_RUN=1]
	$(PHP_T) php yii supplier/from-cloud $(if $(AS),--user=$(AS)) $(if $(DRY_RUN),--dry-run=1)

attack-status: ## Show the loaded MITRE ATT&CK release
	$(PHP_T) php yii attack/status

attack-update: ## Download the latest MITRE ATT&CK Enterprise release, refresh data/attack and import it
	$(PHP_T) php yii attack/update

edge-pull: ## Pull Cloudflare traffic and update edge findings now (the scheduler container does it every EDGE_PULL_INTERVAL min): make edge-pull [AS=jdoe] [MINUTES=60] [DRY_RUN=1]
	$(PHP_T) php yii edge/pull $(if $(AS),--user=$(AS)) $(if $(MINUTES),--minutes=$(MINUTES)) $(if $(DRY_RUN),--dry-run=1)

edge-status: ## Show the Cloudflare analytics limits for the zone and the recent edge pulls
	$(PHP_T) php yii edge/status

expire-blocks: ## Take expired blocklist entries out of Cloudflare (edge-pull also does this)
	$(PHP_T) php yii intel/expire-blocks

cloudflare-migrate: ## One-off: move "Add to Cloudflare" entries from the old rule to the IP list + "Greywarden Blocklist" rule [OLD_RULE="Block Bad Actors"] [DRY_RUN=1]
	$(PHP_T) php yii intel/cloudflare-migrate $(if $(OLD_RULE),--old-rule="$(OLD_RULE)") $(if $(DRY_RUN),--dry-run=1)

intel-status: ## Show the local threat-intel feed status
	$(PHP_T) php yii intel/status

intel-update: ## Download all threat-intel feeds now (Feodo, Spamhaus DROP, FireHOL, Tor, ...)
	$(PHP_T) php yii intel/update --force=1

# MariaDB commands run inside the db container and use its own MARIADB_* env (no secrets echoed here)
DB_ROOT := $(DC) exec -T db sh -c

reset-db: ## DROP and recreate the database, then migrate (destroys all data!)
	@read -p "This will DELETE all data in '$(DB_NAME)'. Type 'yes' to continue: " ans; [ "$$ans" = "yes" ]
	@$(DB_ROOT) 'mariadb -uroot -p"$$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$$MARIADB_DATABASE\`; CREATE DATABASE \`$$MARIADB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$$MARIADB_DATABASE\`.* TO \"$$MARIADB_USER\"@\"%\"; FLUSH PRIVILEGES;"'
	@$(PHP_T) sh -c 'rm -rf runtime/cache/*'
	@echo "Database $(DB_NAME) recreated."
	@$(MAKE) --no-print-directory migrate

test-db: ## Create the test database (idempotent)
	@$(DB_ROOT) 'mariadb -uroot -p"$$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`$(DB_TEST_NAME)\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$(DB_TEST_NAME)\`.* TO \"$$MARIADB_USER\"@\"%\"; FLUSH PRIVILEGES;"'

test: test-db ## Run the PHPUnit test suite against the test database
	$(DC) exec -T -e DB_NAME=$(DB_TEST_NAME) php sh -c 'php yii migrate --interactive=0 > /dev/null && vendor/bin/phpunit $(ARGS)'

test-all: ## All suites (unit, functional, smoke) against a throwaway test DB; ARGS="--no-smoke --coverage"
	./scripts/run-all-tests.sh $(ARGS)

smoke: ## HTTP smoke test only; refuses the live DB (use make test-all, or SMOKE_DB_NAME/SMOKE_BASE_URL)
	./scripts/smoke-test.sh

hooks: ## Install the git hooks in .githooks (leak check, commit messages, signed public pushes)
	git config core.hooksPath .githooks
	@echo "Hooks installed. Private denylist: $${GREYWARDEN_DENYLIST:-$$HOME/.config/greywarden/denylist}"

leak-check: ## Check the index (or a commit: make leak-check REV=v0.1.0-alpha) for private files and terms
	./scripts/leak-check.sh $(if $(REV),--tree $(REV))

db-shell: ## Open a MariaDB client shell
	$(DC) exec db sh -c 'mariadb -u"$$MARIADB_USER" -p"$$MARIADB_PASSWORD" "$$MARIADB_DATABASE"'

deploy: ## PRODUCTION checkout only: deploy a signed release tag (make deploy REF=v0.2.0-alpha)
	./scripts/deploy.sh $(REF)

backup: ## Dump the database to backups/<db>-<timestamp>.sql.gz (starts db if needed)
	BACKUP_DIR="$(BACKUP_DIR)" ./scripts/backup.sh

restore: ## Restore a dump: make restore FILE=backups/greywarden-....sql.gz
	@test -n "$(FILE)" || (echo "Usage: make restore FILE=backups/xxx.sql.gz" && exit 1)
	@gunzip -c "$(FILE)" | $(DB_ROOT) 'mariadb -uroot -p"$$MARIADB_ROOT_PASSWORD" "$$MARIADB_DATABASE"'
	@$(PHP_T) sh -c 'rm -rf runtime/cache/*'
	@echo "Restored $(FILE) into $(DB_NAME)."
