#!/usr/bin/env bash
# ConciliaRH — genera .env de DESARROLLO con secretos aleatorios.
# No sobrescribe un .env existente.
set -euo pipefail
umask 077   # el .env se crea ya con permisos 600
cd "$(dirname "$0")/.."

if [[ -f .env ]]; then
  echo ".env ya existe; no se modifica."
  exit 0
fi

rand_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }

cat > .env <<EOF
# Generado por scripts/dev-init.sh — SOLO DESARROLLO. No versionar.
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8120
APP_TIMEZONE=America/Mexico_City
APP_KEY=$(rand_hex 32)
ACCOUNT_HMAC_KEY=$(rand_hex 32)
# Cifrado AES-256-GCM de cuentas/CLABE: anillo "id:hex" separado por comas + id activo (rotación en docs/IDENTITY_MATCHING.md)
ACCOUNT_ENC_KEY_ID=k1
ACCOUNT_ENC_KEYS=k1:$(rand_hex 32)
# Cifrado en reposo de reportes con datos de personas (docs/REPORT_ENCRYPTION.md): llave DISTINTA de las de cuentas
REPORT_ENC_KEY_ID=r1
REPORT_ENC_KEYS=r1:$(rand_hex 32)

DB_HOST=db
DB_PORT=5432
DB_DATABASE=conciliarh
DB_USERNAME=conciliarh_web
DB_PASSWORD=$(rand_hex 16)
DB_SSLMODE=prefer
DB_MIGRATE_USERNAME=conciliarh_owner
DB_MIGRATE_PASSWORD=$(rand_hex 16)
# Superusuario del contenedor de BD (sólo desarrollo; la aplicación nunca lo usa)
DB_SUPERUSER_PASSWORD=$(rand_hex 16)

WORKER_DB_USERNAME=conciliarh_worker
WORKER_DB_PASSWORD=$(rand_hex 16)
WORKER_HEARTBEAT_TIMEOUT=120
WORKER_POLL_INTERVAL=5

# Rol de servicio del scheduler de reportes (bin/scheduler.php); distinto del worker que lee Excel no confiables
SCHEDULER_DB_USERNAME=conciliarh_scheduler
SCHEDULER_DB_PASSWORD=$(rand_hex 16)

SESSION_SECURE=false
SESSION_IDLE_MINUTES=30
SESSION_ABSOLUTE_HOURS=10
MAX_UPLOAD_SIZE=26214400
DB_TEST_DATABASE=conciliarh_test

DEV_UID=$(id -u)
DEV_GID=$(id -g)
DEV_WEB_PORT=8120
DEV_DB_PORT=55432
EOF
chmod 600 .env
echo ".env de desarrollo generado (permisos 600)."
