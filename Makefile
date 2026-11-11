# ConciliaRH — atajos de DESARROLLO (excluido del paquete de producción).
# DOCKER_CONTEXT: en Linux con Docker Desktop se recomienda `make ... DOCKER_CONTEXT=default`
# (motor nativo): el uso compartido de archivos de Docker Desktop no propaga de forma fiable
# los archivos guardados por renombrado atómico (ver README › Troubleshooting).
DOCKER_CONTEXT ?=
ifneq ($(strip $(DOCKER_CONTEXT)),)
export DOCKER_CONTEXT
endif
TEST_DB ?= conciliarh_test
COMPOSE = docker compose --env-file .env -f infra/docker/compose.yml
# Servicios con credenciales del propietario del esquema (sólo bajo demanda)
TOOLS = $(COMPOSE) --profile tools run --rm --no-deps tools
.DEFAULT_GOAL := help

help: ## Muestra esta ayuda
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-16s %s\n", $$1, $$2}'

init: ## Genera .env de desarrollo, construye imágenes, instala dependencias y migra
	@bash scripts/dev-init.sh
	$(COMPOSE) build
	$(COMPOSE) up -d db web
	$(COMPOSE) exec -T web composer install --no-interaction
	$(TOOLS) php bin/migrate.php up
	$(TOOLS) php bin/migrate.php up --database=$${DB_TEST_DATABASE:-conciliarh_test}
	$(COMPOSE) up -d worker

up: ## Levanta el entorno
	$(COMPOSE) up -d

down: ## Detiene el entorno (conserva datos)
	$(COMPOSE) down

logs: ## Sigue los logs de los contenedores
	$(COMPOSE) logs -f --tail=100

migrate: db-roles ## Aplica migraciones pendientes (BD principal y de pruebas)
	$(TOOLS) php bin/migrate.php up
	$(TOOLS) php bin/migrate.php up --database=$${DB_TEST_DATABASE:-conciliarh_test}

migrate-status: ## Estado de migraciones
	$(TOOLS) php bin/migrate.php status

god: ## Crea el usuario GOD (interactivo)
	$(COMPOSE) --profile tools run --rm --no-deps -it tools php bin/create-god.php

test: fixtures test-php ## Ejecuta todas las pruebas

fixtures: ## Genera los fixtures Excel FICTICIOS (no se versionan)
	$(TOOLS) php -d memory_limit=1G tests/fixtures/generate.php

test-php: ## PHPUnit (usa la BD de pruebas)
	$(TOOLS) vendor/bin/phpunit

smoke: ## Prueba de humo HTTP (usa storage/dev-credenciales.txt generado por seed-dev)
	@test -f storage/dev-credenciales.txt || { echo "Ejecute make seed-dev primero."; exit 1; }
	SMOKE_USER=dev.god SMOKE_PASSWORD="$$(sed -n 's/^dev.god=//p' storage/dev-credenciales.txt)" bash tests/e2e/smoke.sh

security-smoke: ## Pruebas de seguridad ofensiva HTTP (SQLi, XSS, IDOR, CSRF, privilegios, fórmulas, SSRF PDF, actor) contra desarrollo
	@test -f storage/dev-credenciales.txt || { echo "Ejecute make seed-dev primero."; exit 1; }
	COMPOSE="$(COMPOSE)" bash tests/e2e/security-smoke.sh

shell: ## Shell en el contenedor web
	$(COMPOSE) exec web bash

test-db-reset: ## Recrea una BD de PRUEBAS (por omisión conciliarh_test; otra con TEST_DB=nombre) y la migra. Sólo desarrollo
	@case "$(TEST_DB)" in conciliarh_test*) ;; *) echo "TEST_DB debe empezar con conciliarh_test"; exit 1;; esac
	@$(COMPOSE) exec -T db sh -c 'psql -v ON_ERROR_STOP=1 -U "$$POSTGRES_USER" -d postgres -c "DROP DATABASE IF EXISTS $(TEST_DB) WITH (FORCE)" -c "CREATE DATABASE $(TEST_DB) OWNER conciliarh_owner" -c "REVOKE ALL ON DATABASE $(TEST_DB) FROM PUBLIC" -c "GRANT CONNECT ON DATABASE $(TEST_DB) TO conciliarh_web, conciliarh_worker, conciliarh_scheduler" -c "ALTER DATABASE $(TEST_DB) SET timezone TO '"'"'America/Mexico_City'"'"'" && psql -v ON_ERROR_STOP=1 -U "$$POSTGRES_USER" -d $(TEST_DB) -c "ALTER SCHEMA public OWNER TO conciliarh_owner" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC" -c "GRANT USAGE ON SCHEMA public TO conciliarh_web, conciliarh_worker, conciliarh_scheduler"'
	$(TOOLS) php bin/migrate.php up --database=$(TEST_DB)

db-roles: ## Crea/actualiza roles de BD agregados después de make init (p. ej. conciliarh_scheduler, FASE 7). Sólo desarrollo
	COMPOSE="$(COMPOSE)" bash scripts/dev-db-roles.sh

scheduler: ## Reportes programados: encola lo vencido y genera lo pendiente (en producción: timer de systemd/cron, ver docs/SCHEDULED_REPORTS.md)
	$(TOOLS) php bin/scheduler.php --run

storage-check: ## Reporta archivos huérfanos o registros sin archivo (sólo lectura; use ARGS=--cuarentena)
	$(COMPOSE) exec -T web php bin/verificar-almacenamiento.php $(ARGS)

demo-dev: ## Datos de DEMOSTRACIÓN ficticios para todas las pantallas (tras seed-dev y smoke); sólo development
	@grep -qE '^APP_ENV=development$$' .env || { echo "Rechazado: APP_ENV no es development."; exit 1; }
	$(TOOLS) php bin/dev-demo.php

seed-dev: ## Crea usuarios de DESARROLLO (dev.*) marcados como tales; rechazado fuera de development
	@grep -qE '^APP_ENV=development$$' .env || { echo "Rechazado: APP_ENV no es development."; exit 1; }
	$(TOOLS) php bin/dev-seed.php

reset-dev: ## DESTRUCTIVO: borra BD y storage de DESARROLLO, reinicializa y siembra (pide confirmación)
	@grep -qE '^APP_ENV=development$$' .env || { echo "Rechazado: APP_ENV no es development."; exit 1; }
	@read -p "Se BORRARÁN la base de datos y storage/imports de DESARROLLO. Escriba BORRAR-DESARROLLO: " c; [ "$$c" = "BORRAR-DESARROLLO" ] || { echo "Cancelado."; exit 1; }
	$(COMPOSE) --profile tools down -v
	find storage/imports -mindepth 1 -not -name .gitkeep -delete 2>/dev/null || true
	$(MAKE) init DOCKER_CONTEXT=$(DOCKER_CONTEXT)
	$(MAKE) seed-dev DOCKER_CONTEXT=$(DOCKER_CONTEXT)

.PHONY: help init up down logs migrate migrate-status god test fixtures test-php smoke security-smoke shell storage-check seed-dev reset-dev
