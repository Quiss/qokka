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
PHP_BOOTSTRAP := docker run --rm \
	--user "$(UID):$(GID)" \
	--env HOME=/tmp \
	--env COMPOSER_HOME=/tmp/composer \
	--volume "$(PWD):/var/www/html" \
	--workdir /var/www/html \
	--entrypoint /bin/sh \
	$(PHP_BOOTSTRAP_IMAGE) -lc

first:
	@test -f .env || cp .env.example .env
	$(PHP_BOOTSTRAP) 'composer install --ignore-platform-req=ext-intl --no-interaction'
	@if ! grep -Eq '^APP_KEY=base64:.+$$' .env; then \
		$(PHP_BOOTSTRAP) 'php artisan key:generate --force --no-interaction'; \
	else \
		echo "APP_KEY already exists; keeping it unchanged."; \
	fi
deploy:
	git pull
	docker compose exec app php artisan optimize
	docker compose exec app php artisan migrate --force
	docker compose exec app php artisan octane:reload

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
	docker compose exec -T pgsql pg_dump -U $(DB_USERNAME) -d $(DB_DATABASE) | gzip > $(BACKUP_DIR)/db-$(TIMESTAMP).sql.gz
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
	gunzip -c $$LATEST | docker compose exec -T pgsql psql -U $(DB_USERNAME) -d $(DB_DATABASE)
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

# Restart Horizon (graceful)
restart-horizon:
	docker compose exec horizon php artisan horizon:terminate
	@echo "Horizon will restart automatically"

# Restart Scheduler (with lock cleanup)
restart-scheduler:
	docker compose exec app php artisan schedule:clear-cache
	docker compose restart scheduler
	docker compose exec app php artisan schedule:clear-cache
	@echo "Scheduler restarted with cleared locks"

restart-reverb:
	docker compose restart reverb

# Restart all workers (horizon + scheduler)
restart-workers: restart-horizon restart-scheduler restart-reverb
	@echo "All workers restarted"

# Full reload (octane + horizon + scheduler)
reload-all:
	docker compose exec app php artisan optimize
	docker compose exec app php artisan octane:reload
	docker compose exec app php artisan horizon:terminate
	docker compose exec app php artisan schedule:clear-cache
	docker compose restart scheduler
	docker compose restart reverb
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
        restart-horizon restart-scheduler restart-workers reload-all \
        import-db
