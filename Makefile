#!make
include .env

# Variables
BACKUP_DIR := ../backups
BACKUP_KEEP := 3
TIMESTAMP := $(shell date +%d%m%Y-%H%M%S)

# ===========================================
# Production Deployment
# ===========================================

UID := $(shell id -u)
GID := $(shell id -g)
PWD := $(shell pwd)
PHP_BOOTSTRAP_IMAGE ?= serversideup/php:8.5-cli
PUBLISH_DRAIN_TIMEOUT ?= 620
MEDIA_DOWNLOAD_DRAIN_TIMEOUT ?= 430
PUBLISH_QUEUE ?= redis:publish
TELEGRAM_QUEUE ?= redis:telegram
MEDIA_DOWNLOAD_HIGH_QUEUE ?= redis:media-download-high
MEDIA_DOWNLOAD_LOW_QUEUE ?= redis:media-download-low
POSTGRES_MIN_CONNECTIONS ?= 200
PHP_BOOTSTRAP := docker run --rm \
	--user "$(UID):$(GID)" \
	--env HOME=/tmp \
	--env COMPOSER_HOME=/tmp/composer \
	--volume "$(PWD):/var/www/html" \
	--workdir /var/www/html \
	--entrypoint /bin/sh \
	$(PHP_BOOTSTRAP_IMAGE) -lc
PRODUCTION_COMPOSE ?= docker compose -f docker-compose.production.yml

first:
	@test -f .env || cp .env.example .env
	$(PHP_BOOTSTRAP) 'composer install --ignore-platform-req=ext-intl --no-interaction'
	@if ! grep -Eq '^APP_KEY=base64:.+$$' .env; then \
		$(PHP_BOOTSTRAP) 'php artisan key:generate --force --no-interaction'; \
	else \
		echo "APP_KEY already exists; keeping it unchanged."; \
	fi
deploy:
	@set -eu; \
	DEPLOY_READY=0; \
	trap 'if [ "$$DEPLOY_READY" -ne 1 ]; then echo "Deploy failed before MadelineProto readiness; queues remain paused."; fi' 0 1 2 15; \
	$(PRODUCTION_COMPOSE) exec horizon php artisan horizon:pause; \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:pause $(PUBLISH_QUEUE); \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:pause $(TELEGRAM_QUEUE); \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:pause $(MEDIA_DOWNLOAD_HIGH_QUEUE); \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:pause $(MEDIA_DOWNLOAD_LOW_QUEUE); \
	$(PRODUCTION_COMPOSE) exec app php artisan deliveries:wait-for-publishing --timeout=$(PUBLISH_DRAIN_TIMEOUT); \
	echo "Waiting for active Telegram media downloads (timeout: $(MEDIA_DOWNLOAD_DRAIN_TIMEOUT)s)..."; \
	$(PRODUCTION_COMPOSE) exec app php artisan telegram:wait-for-media-downloads --timeout=$(MEDIA_DOWNLOAD_DRAIN_TIMEOUT); \
	$(PRODUCTION_COMPOSE) stop --timeout 370 horizon; \
	$(PRODUCTION_COMPOSE) stop --timeout 120 madeline; \
	git pull; \
	$(PHP_BOOTSTRAP) 'composer install --ignore-platform-req=ext-intl --no-dev --prefer-dist --no-interaction --optimize-autoloader'; \
	$(PRODUCTION_COMPOSE) exec app php artisan optimize; \
	$(PRODUCTION_COMPOSE) exec app php artisan migrate --force; \
	$(MAKE) reconcile-containers; \
	$(PRODUCTION_COMPOSE) exec madeline php artisan telegram:health --no-interaction; \
	$(MAKE) restart-app; \
	$(MAKE) restart-scheduler; \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:continue $(MEDIA_DOWNLOAD_HIGH_QUEUE); \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:continue $(MEDIA_DOWNLOAD_LOW_QUEUE); \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:continue $(TELEGRAM_QUEUE); \
	$(PRODUCTION_COMPOSE) exec app php artisan queue:continue $(PUBLISH_QUEUE); \
	DEPLOY_READY=1; \
	trap - 0 1 2 15

deploy-full: backup deploy
	@echo "Full deploy with backup completed"

# ===========================================
# Staging
# ===========================================

staging:
	docker compose -f docker-compose.staging.yml down --remove-orphans
	docker compose run --rm --no-deps app npm run build
	docker compose -f docker-compose.staging.yml up -d

# ===========================================
# Build
# ===========================================

build:
	docker compose run --rm --no-deps app npm run build

# ===========================================
# Backups
# ===========================================

# Create backup directory if not exists
backup-init:
	@mkdir -p $(BACKUP_DIR)

# Full backup (database + storage)
backup: backup-init backup-db backup-storage backup-cleanup
	@echo "Backup completed: $(TIMESTAMP)"

# Database backup
backup-db: backup-init
	@echo "Creating database backup..."
	$(PRODUCTION_COMPOSE) exec -T pgsql pg_dump -U $(DB_USERNAME) -d $(DB_DATABASE) | gzip > $(BACKUP_DIR)/db-$(TIMESTAMP).sql.gz
	@echo "Database backup saved: $(BACKUP_DIR)/db-$(TIMESTAMP).sql.gz"

# Storage backup
backup-storage: backup-init
	@echo "Creating storage backup..."
	tar -czf $(BACKUP_DIR)/storage-$(TIMESTAMP).tar.gz -C . storage/app
	@echo "Storage backup saved: $(BACKUP_DIR)/storage-$(TIMESTAMP).tar.gz"

# Cleanup old backups (keep last N)
backup-cleanup:
	@echo "Cleaning old backups (keeping last $(BACKUP_KEEP))..."
	@cd $(BACKUP_DIR) && ls -t db-*.sql.gz 2>/dev/null | tail -n +$$(($(BACKUP_KEEP)+1)) | xargs -r rm -f
	@cd $(BACKUP_DIR) && ls -t storage-*.tar.gz 2>/dev/null | tail -n +$$(($(BACKUP_KEEP)+1)) | xargs -r rm -f
	@echo "Cleanup completed"

# List existing backups
backup-list:
	@echo "=== Database backups ==="
	@ls -lh $(BACKUP_DIR)/db-*.sql.gz 2>/dev/null || echo "No database backups"
	@echo ""
	@echo "=== Storage backups ==="
	@ls -lh $(BACKUP_DIR)/storage-*.tar.gz 2>/dev/null || echo "No storage backups"

# ===========================================
# Restore
# ===========================================

# Restore database from latest backup
restore-db-latest:
	@echo "Restoring database from latest backup..."
	@LATEST=$$(ls -t $(BACKUP_DIR)/db-*.sql.gz 2>/dev/null | head -1); \
	if [ -z "$$LATEST" ]; then \
		echo "No backup found"; exit 1; \
	fi; \
	echo "Restoring from: $$LATEST"; \
	gunzip -c $$LATEST | $(PRODUCTION_COMPOSE) exec -T pgsql psql -U $(DB_USERNAME) -d $(DB_DATABASE)
	@echo "Database restored"

# Restore storage from latest backup
restore-storage-latest:
	@echo "Restoring storage from latest backup..."
	@LATEST=$$(ls -t $(BACKUP_DIR)/storage-*.tar.gz 2>/dev/null | head -1); \
	if [ -z "$$LATEST" ]; then \
		echo "No backup found"; exit 1; \
	fi; \
	echo "Restoring from: $$LATEST"; \
	tar -xzf $$LATEST -C .
	@echo "Storage restored"

# ===========================================
# Workers Restart
# ===========================================

# Apply changes from docker-compose.production.yml and remove stale containers.
reconcile-containers:
	$(PRODUCTION_COMPOSE) up -d --remove-orphans --wait --wait-timeout 180
	$(MAKE) verify-postgres-capacity

verify-postgres-capacity:
	@ACTUAL_CONNECTIONS=$$($(PRODUCTION_COMPOSE) exec -T pgsql psql -U $(DB_USERNAME) -d $(DB_DATABASE) -Atc "SHOW max_connections"); \
	if [ "$$ACTUAL_CONNECTIONS" -lt "$(POSTGRES_MIN_CONNECTIONS)" ]; then \
		echo "PostgreSQL max_connections=$$ACTUAL_CONNECTIONS, minimum $(POSTGRES_MIN_CONNECTIONS). Recreate the pgsql container."; \
		exit 1; \
	fi; \
	echo "PostgreSQL max_connections=$$ACTUAL_CONNECTIONS"

# Reload Octane workers gracefully
restart-app:
	$(PRODUCTION_COMPOSE) exec app php artisan octane:reload
	@echo "Application workers reloaded"

# Restart Horizon (graceful)
restart-horizon:
	$(PRODUCTION_COMPOSE) exec horizon php artisan horizon:terminate
	@echo "Horizon will restart automatically"

# Restart Scheduler (with lock cleanup)
restart-scheduler:
	$(PRODUCTION_COMPOSE) exec app php artisan schedule:clear-cache
	$(PRODUCTION_COMPOSE) restart scheduler
	$(PRODUCTION_COMPOSE) exec app php artisan schedule:clear-cache
	@echo "Scheduler restarted with cleared locks"

# Restart MadelineProto gracefully so sessions can be serialized
restart-madeline:
	$(PRODUCTION_COMPOSE) restart --timeout 120 madeline
	$(PRODUCTION_COMPOSE) up -d --wait --wait-timeout 180 madeline
	@echo "MadelineProto listener restarted"

# Restart all background workers
restart-workers: restart-horizon restart-scheduler restart-madeline
	@echo "All workers restarted"

# Restart the application and all background workers
restart-all: restart-app restart-workers
	@echo "Application and all workers restarted"

# Rebuild Laravel caches and reload all long-running application services
reload-all: reconcile-containers
	$(PRODUCTION_COMPOSE) exec app php artisan optimize
	$(MAKE) restart-all
	@echo "All services reloaded"

# ===========================================
# Local Import (via Sail)
# ===========================================

# Import production db backup into local Sail postgres
# Usage: make import-db FILE=db-22022026-010001.sql.gz
import-db:
	@if [ -z "$(FILE)" ]; then echo "Usage: make import-db FILE=db-22022026-120000.sql.gz"; exit 1; fi
	@if [ ! -f "$(FILE)" ]; then echo "File not found: $(FILE)"; exit 1; fi
	@echo "Terminating active connections..."
	vendor/bin/sail exec pgsql psql -U $(DB_USERNAME) -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$(DB_DATABASE)' AND pid <> pg_backend_pid();"
	@echo "Dropping and recreating local database..."
	vendor/bin/sail exec pgsql dropdb -U $(DB_USERNAME) --if-exists $(DB_DATABASE)
	vendor/bin/sail exec pgsql createdb -U $(DB_USERNAME) $(DB_DATABASE)
	@echo "Importing $(FILE)..."
	gunzip -c $(FILE) | vendor/bin/sail exec -T pgsql psql -U $(DB_USERNAME) -d $(DB_DATABASE)
	@echo "Running migrations..."
	vendor/bin/sail artisan migrate --force
	@echo "Import completed"

.PHONY: first deploy deploy-full staging build \
        backup backup-init backup-db backup-storage backup-cleanup backup-list \
        restore-db-latest restore-storage-latest \
        reconcile-containers verify-postgres-capacity \
        restart-app restart-horizon restart-scheduler restart-madeline \
        restart-workers restart-all reload-all \
        import-db
