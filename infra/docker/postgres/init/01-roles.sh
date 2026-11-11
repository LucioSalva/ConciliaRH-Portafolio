#!/bin/sh
# ConciliaRH — inicialización de PostgreSQL SOLO DESARROLLO.
# Se ejecuta una única vez cuando el volumen está vacío (como superusuario `postgres`).
# Crea los tres roles de la aplicación con mínimo privilegio:
#   conciliarh_owner  (NOSUPERUSER) propietario de las bases y del esquema public
#   conciliarh_web    rol de la aplicación PHP
#   conciliarh_worker rol del motor Python
# En producción estos pasos los realiza el DBA (docs/DEPLOYMENT.md).
set -eu

: "${CONCILIARH_OWNER_PASSWORD:?falta}"
: "${CONCILIARH_WEB_PASSWORD:?falta}"
: "${CONCILIARH_WORKER_PASSWORD:?falta}"
: "${CONCILIARH_SCHEDULER_PASSWORD:?falta}"
: "${CONCILIARH_DB:=conciliarh}"
: "${CONCILIARH_TEST_DB:=conciliarh_test}"

psql -v ON_ERROR_STOP=1 \
     -v owner_pw="$CONCILIARH_OWNER_PASSWORD" \
     -v web_pw="$CONCILIARH_WEB_PASSWORD" \
     -v worker_pw="$CONCILIARH_WORKER_PASSWORD" \
     -v scheduler_pw="$CONCILIARH_SCHEDULER_PASSWORD" \
     --username "$POSTGRES_USER" --dbname postgres <<'SQL'
CREATE ROLE conciliarh_owner  LOGIN PASSWORD :'owner_pw'  NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE conciliarh_web    LOGIN PASSWORD :'web_pw'    NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE conciliarh_worker LOGIN PASSWORD :'worker_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE conciliarh_scheduler LOGIN PASSWORD :'scheduler_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;
SQL

for db in "$CONCILIARH_DB" "$CONCILIARH_TEST_DB"; do
  psql -v ON_ERROR_STOP=1 -v db="$db" --username "$POSTGRES_USER" --dbname postgres <<'SQL'
CREATE DATABASE :"db" OWNER conciliarh_owner;
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO conciliarh_web, conciliarh_worker, conciliarh_scheduler;
ALTER DATABASE :"db" SET timezone TO 'America/Mexico_City';
SQL
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<'SQL'
ALTER SCHEMA public OWNER TO conciliarh_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO conciliarh_web, conciliarh_worker, conciliarh_scheduler;
SQL
done
