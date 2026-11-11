#!/usr/bin/env bash
# Crea (o actualiza) los roles de BD que agregaron fases posteriores a la inicialización del contenedor de desarrollo.
# Idempotente. Sólo DESARROLLO: en producción el DBA crea el rol (docs/SCHEDULED_REPORTS.md §6).
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="${COMPOSE:-docker compose --env-file .env -f infra/docker/compose.yml}"
grep -q '^SCHEDULER_DB_PASSWORD=.\+' .env || {
  pw=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
  sed -i '/^SCHEDULER_DB_/d' .env
  printf '\n# Rol del scheduler de reportes (FASE 7)\nSCHEDULER_DB_USERNAME=conciliarh_scheduler\nSCHEDULER_DB_PASSWORD=%s\n' "$pw" >> .env
  echo "SCHEDULER_DB_PASSWORD agregado a .env"
}
pw=$(sed -n 's/^SCHEDULER_DB_PASSWORD=//p' .env)
db=$(sed -n 's/^DB_DATABASE=//p' .env); db=${db:-conciliarh}
test_db=$(sed -n 's/^DB_TEST_DATABASE=//p' .env); test_db=${test_db:-conciliarh_test}
$COMPOSE exec -T db psql -v ON_ERROR_STOP=1 -q -U postgres -d postgres -v pw="$pw" -v db="$db" -v tdb="$test_db" <<'SQL'
SELECT format('CREATE ROLE conciliarh_scheduler LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE', :'pw')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'conciliarh_scheduler') \gexec
ALTER ROLE conciliarh_scheduler PASSWORD :'pw';
GRANT CONNECT ON DATABASE :"db" TO conciliarh_scheduler;
SELECT format('GRANT CONNECT ON DATABASE %I TO conciliarh_scheduler', :'tdb') WHERE EXISTS (SELECT 1 FROM pg_database WHERE datname = :'tdb') \gexec
SQL
for d in "$db" "$test_db"; do
  $COMPOSE exec -T db psql -q -U postgres -d "$d" -c "GRANT USAGE ON SCHEMA public TO conciliarh_scheduler" 2>/dev/null || true
done
echo "Rol conciliarh_scheduler listo."
